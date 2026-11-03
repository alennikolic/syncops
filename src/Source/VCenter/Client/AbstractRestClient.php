<?php
// src/Source/VCenter/Client/AbstractRestClient.php
//
// Zajednicko telo oba REST dijalekta. Razlike koje potomci popunjavaju:
//
//   sessionPath()  /api/session            naspram  /rest/com/vmware/cis/session
//   vcenterPath()  /api/vcenter            naspram  /rest/vcenter
//   unwrap()       odgovor je sam niz      naspram  {"value": niz}
//   filter()       ?clusters=a&clusters=b  naspram  ?filter.clusters.1=a&filter.clusters.2=b
//
// Sve ostalo — prijava, zaglavlje sa tokenom, prevodjenje gresaka, brojanje
// poziva — je isto i stoji ovde.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SensitiveParameter;
use SyncOps\Source\VCenter\VCenterException;

abstract class AbstractRestClient implements ClientInterface
{
    protected const SESSION_HEADER = 'vmware-api-session-id';

    /** Limiti koje vSphere nameće listama; preko njih vraca gresku, ne isecak. */
    protected const LIMIT_VMS   = 4000;
    protected const LIMIT_HOSTS = 1000;

    private ?string $token = null;
    private ?string $productVersion = null;
    private bool $versionChecked = false;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {
    }

    // -----------------------------------------------------------------
    // Razlike izmedju dijalekata
    // -----------------------------------------------------------------

    abstract protected function sessionPath(): string;

    abstract protected function vcenterPath(): string;

    /** Skida omotac oko korisnog sadrzaja odgovora. */
    abstract protected function unwrap(mixed $decoded): mixed;

    /**
     * Pretvara filter u parove za query string.
     *
     * @param array<string, list<string>> $filters
     * @return list<array{0: string, 1: string}>
     */
    abstract protected function filter(array $filters): array;

    // -----------------------------------------------------------------
    // Sesija
    // -----------------------------------------------------------------

    public function login(): void
    {
        if ($this->token !== null) {
            return;
        }

        $response = $this->http->request(
            method: 'POST',
            path: $this->sessionPath(),
            basicAuth: [$this->username, $this->password],
        );

        if ($response->status === 401) {
            throw VCenterException::badCredentials();
        }

        if ($response->status === 404) {
            throw VCenterException::wrongFlavor($this->sessionPath());
        }

        if (!$response->isOk()) {
            throw VCenterException::unexpected(
                $response->status,
                $this->sessionPath(),
                $response->errorMessage()
            );
        }

        $token = $this->unwrap($response->json());

        if (!is_string($token) || $token === '') {
            throw new VCenterException(
                'vCenter je prihvatio prijavu ali nije vratio sesijski token.'
            );
        }

        $this->token = $token;
    }

    public function logout(): void
    {
        if ($this->token === null) {
            return;
        }

        // Neuspela odjava se ne prijavljuje kao greska — sesija istekne sama.
        try {
            $this->http->request('DELETE', $this->sessionPath(), headers: $this->authHeaders());
        } catch (VCenterException) {
            // namerno prazno
        }

        $this->token = null;
    }

    public function productVersion(): ?string
    {
        if ($this->versionChecked) {
            return $this->productVersion;
        }

        $this->versionChecked = true;

        // Read-only nalog cesto nema prava na /appliance putanje, pa je
        // neuspeh ocekivan ishod a ne greska.
        try {
            $response = $this->http->request(
                'GET',
                $this->appliancePath() . '/system/version',
                headers: $this->authHeaders()
            );

            if (!$response->isOk()) {
                return null;
            }

            $value = $this->unwrap($response->json());

            if (is_array($value) && isset($value['version'])) {
                $this->productVersion = (string) $value['version'];
            }
        } catch (VCenterException) {
            return null;
        }

        return $this->productVersion;
    }

    // -----------------------------------------------------------------
    // Inventar
    // -----------------------------------------------------------------

    public function listDatacenters(): array
    {
        return $this->getList('/datacenter', [], 'datacentri', 1000);
    }

    public function listClusters(array $datacenterIds = []): array
    {
        return $this->getList(
            '/cluster',
            $datacenterIds === [] ? [] : ['datacenters' => $datacenterIds],
            'klasteri',
            1000
        );
    }

    public function listHosts(array $clusterIds = [], array $datacenterIds = []): array
    {
        $filters = [];

        if ($clusterIds !== []) {
            $filters['clusters'] = $clusterIds;
        }

        if ($datacenterIds !== []) {
            $filters['datacenters'] = $datacenterIds;
        }

        return $this->getList('/host', $filters, 'hostovi', self::LIMIT_HOSTS);
    }

    public function getHostHardware(string $hostId): ?array
    {
        // REST nema hardverske podatke o hostu. Namerno null, ne izuzetak:
        // kolektor to tumaci kao "nije prikupljeno" i upisuje hardware_source
        // kao NULL. SOAP klijent ce kasnije vracati pravi niz.
        return null;
    }

    public function listVms(array $clusterIds = [], array $datacenterIds = []): array
    {
        $filters = [];

        if ($clusterIds !== []) {
            $filters['clusters'] = $clusterIds;
        }

        if ($datacenterIds !== []) {
            $filters['datacenters'] = $datacenterIds;
        }

        return $this->getList('/vm', $filters, 'virtuelne masine', self::LIMIT_VMS);
    }

    public function listVmsOnHost(string $hostId): array
    {
        return $this->getList('/vm', ['hosts' => [$hostId]], 'virtuelne masine na hostu', self::LIMIT_VMS);
    }

    public function getVm(string $vmId): array
    {
        $response = $this->http->request(
            'GET',
            $this->vcenterPath() . '/vm/' . rawurlencode($vmId),
            headers: $this->authHeaders()
        );

        $this->guard($response, '/vm/' . $vmId, 'citanje detalja masine');

        $value = $this->unwrap($response->json());

        return is_array($value) ? $value : [];
    }

    public function getVmGuestIdentity(string $vmId): ?array
    {
        $response = $this->http->request(
            'GET',
            $this->vcenterPath() . '/vm/' . rawurlencode($vmId) . '/guest/identity',
            headers: $this->authHeaders()
        );

        // 503 = VMware Tools ne rade; 404 = masina ugasena ili bez gosta.
        // Oboje su ocekivana stanja, ne greske.
        if ($response->status === 503 || $response->status === 404) {
            return null;
        }

        if (!$response->isOk()) {
            return null;
        }

        $value = $this->unwrap($response->json());

        return is_array($value) ? $value : null;
    }

    public function callCount(): int
    {
        return $this->http->callCount();
    }

    // -----------------------------------------------------------------

    /**
     * @param array<string, list<string>> $filters
     * @return list<array<string, mixed>>
     */
    protected function getList(string $path, array $filters, string $what, int $limit): array
    {
        $response = $this->http->request(
            'GET',
            $this->vcenterPath() . $path,
            query: $this->filter($filters),
            headers: $this->authHeaders()
        );

        if ($response->isTooManyObjects()) {
            throw VCenterException::tooManyObjects($what, $limit);
        }

        $this->guard($response, $path, 'citanje liste: ' . $what);

        $value = $this->unwrap($response->json());

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        if ($this->token === null) {
            throw new VCenterException('Poziv pre prijave na vCenter.');
        }

        return [self::SESSION_HEADER => $this->token];
    }

    protected function guard(Response $response, string $path, string $what): void
    {
        if ($response->isOk()) {
            return;
        }

        throw match ($response->status) {
            401     => VCenterException::badCredentials(),
            403     => VCenterException::forbidden($what),
            404     => VCenterException::wrongFlavor($this->vcenterPath() . $path),
            default => VCenterException::unexpected($response->status, $path, $response->errorMessage()),
        };
    }

    abstract protected function appliancePath(): string;
}
