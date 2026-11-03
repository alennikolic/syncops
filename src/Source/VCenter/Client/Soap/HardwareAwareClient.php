<?php
// src/Source/VCenter/Client/HardwareAwareClient.php
//
// Spaja REST i SOAP iza istog ugovora: sve ide kroz REST, osim hardverskih
// podataka o hostovima, koje REST nema pa ih dovlaci SOAP.
//
// Ovo je ono zbog cega ClientInterface postoji od pocetka — kolektori i maperi
// se nisu dirali, samo je dodat omotac.
//
// Neuspeh SOAP-a NE OBARA prikupljanje. Ako je /sdk zatvoren ili nalog nema
// prava, hardverska polja ostaju prazna, upozorenje se zabelezi, a run ostaje
// uspesan. Razlog: delimican run bi trajno onemogucio oznaku "nije vidjeno u
// poslednjem prikupljanju", koja racuna samo uspesne run-ove — pa bi jedan
// zatvoren port ugasio korisnu funkciju u celom GUI-ju.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SyncOps\Source\VCenter\Client\Soap\SoapHardwareClient;
use SyncOps\Source\VCenter\VCenterException;

final class HardwareAwareClient implements ClientInterface
{
    /** @var list<string> Upozorenja koja ne obaraju run */
    private array $warnings = [];

    private bool $soapAvailable = true;

    public function __construct(
        private readonly ClientInterface $rest,
        private readonly SoapHardwareClient $soap,
    ) {
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    // -----------------------------------------------------------------
    // Jedini razlog postojanja ove klase
    // -----------------------------------------------------------------

    public function getHostHardware(string $hostId): ?array
    {
        if (!$this->soapAvailable) {
            return null;
        }

        try {
            return $this->soap->getHardware($hostId);
        } catch (VCenterException $e) {
            // Prvi neuspeh gasi SOAP za ceo run: ako /sdk ne radi, nema smisla
            // pokusavati za svaki naredni host.
            $this->soapAvailable = false;
            $this->warnings[]    = 'Hardverski podaci o hostovima nisu prikupljeni: ' . $e->getMessage();

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Sesija: obe strane
    // -----------------------------------------------------------------

    public function login(): void
    {
        $this->rest->login();

        try {
            $this->soap->login();
        } catch (VCenterException $e) {
            $this->soapAvailable = false;
            $this->warnings[]    = 'Prijava na SOAP krajnju tacku nije uspela, '
                                 . 'pa hardverski podaci o hostovima ostaju prazni: ' . $e->getMessage();
        }
    }

    public function logout(): void
    {
        // Obe sesije se zatvaraju i kada je jedna pukla.
        try {
            $this->soap->logout();
        } finally {
            $this->rest->logout();
        }
    }

    public function callCount(): int
    {
        return $this->rest->callCount() + $this->soap->callCount();
    }

    public function productVersion(): ?string
    {
        // SOAP vraca verziju i nalogu koji nema prava na /appliance putanje,
        // pa je ovde cesto popunjena i kada je REST vrati kao null.
        return $this->rest->productVersion() ?? $this->soap->productVersion();
    }

    // -----------------------------------------------------------------
    // Sve ostalo ide nepromenjeno kroz REST
    // -----------------------------------------------------------------

    public function flavor(): string
    {
        return $this->rest->flavor();
    }

    public function listDatacenters(): array
    {
        return $this->rest->listDatacenters();
    }

    public function listClusters(array $datacenterIds = []): array
    {
        return $this->rest->listClusters($datacenterIds);
    }

    public function listHosts(array $clusterIds = [], array $datacenterIds = []): array
    {
        return $this->rest->listHosts($clusterIds, $datacenterIds);
    }

    public function listVms(array $clusterIds = [], array $datacenterIds = []): array
    {
        return $this->rest->listVms($clusterIds, $datacenterIds);
    }

    public function listVmsOnHost(string $hostId): array
    {
        return $this->rest->listVmsOnHost($hostId);
    }

    public function getVm(string $vmId): array
    {
        return $this->rest->getVm($vmId);
    }

    public function getVmGuestIdentity(string $vmId): ?array
    {
        return $this->rest->getVmGuestIdentity($vmId);
    }
}
