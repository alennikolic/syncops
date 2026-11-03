<?php
// src/Source/VCenter/Collector/VmCollector.php
//
// Prikuplja virtuelne masine. Najskuplji deo posla.
//
// Postupak:
//   1. puna lista masina (particionisana po klasteru ako premasi limit)
//   2. jedna filtrirana lista PO HOSTU — tako se saznaje na kom hostu je koja
//      masina, bez dodatnog poziva po masini (5 hostova = 5 poziva umesto 156)
//   3. detalji po masini — jedini nacin da se dodje do instance_uuid
//   4. podaci iz gosta po masini — hostname i IP, samo ako Tools rade
//
// Racunica: 156 masina -> 2 + 5 + 156 + 156 = oko 320 poziva.
//
// Namerno je serijski. Paralelizacija preko curl_multi ima smisla tek kad se
// izmeri stvarno trajanje; dotle bi samo otezala pripisivanje gresaka
// pojedinacnoj masini.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\ClusterDto;
use SyncOps\Source\Dto\HostDto;
use SyncOps\Source\Dto\VmDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\VmMapper;
use SyncOps\Source\VCenter\VCenterException;

final class VmCollector
{
    /** @var list<string> Masine koje su pukle, da run moze da bude "partial" */
    private array $failures = [];

    public function __construct(
        private readonly VmMapper $mapper = new VmMapper(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @param list<ClusterDto> $clusters
     * @param list<HostDto> $hosts
     * @param callable(int, int): void|null $progress Poziva se posle svake masine
     * @return list<VmDto>
     */
    public function collect(
        ClientInterface $client,
        array $clusters,
        array $hosts,
        ?callable $progress = null,
    ): array {
        $this->failures = [];

        $summaries = $this->listAll($client, $clusters);
        $placement = $this->placementByHost($client, $hosts, $clusters);

        $vms   = [];
        $total = count($summaries);
        $done  = 0;

        foreach ($summaries as $moref => $summary) {
            $done++;

            try {
                $detail = $client->getVm($moref);
                $guest  = $client->getVmGuestIdentity($moref);

                $vms[] = $this->mapper->map(
                    $moref,
                    // Ime iz liste je rezerva ako detalji ne vrate ime.
                    $detail + ['name' => $detail['name'] ?? ($summary['name'] ?? '')],
                    $guest,
                    $placement[$moref] ?? ['host' => null, 'cluster' => null, 'folder' => null, 'datacenter' => null],
                );
            } catch (VCenterException $e) {
                // Jedna masina koja pukne ne obara ceo run — belezi se i ide dalje.
                $this->failures[] = $moref . ': ' . $e->getMessage();
            }

            if ($progress !== null) {
                $progress($done, $total);
            }
        }

        return $vms;
    }

    /**
     * Puna lista masina. Ako premasi limit, particionise se po klasteru.
     *
     * @param list<ClusterDto> $clusters
     * @return array<string, array<string, mixed>> moref => sazetak
     */
    private function listAll(ClientInterface $client, array $clusters): array
    {
        try {
            return $this->index($client->listVms());
        } catch (VCenterException $e) {
            if ($clusters === []) {
                throw $e;
            }
        }

        // Particionisanje: po klasteru, pa spajanje uz deduplikaciju po moref-u.
        $all = [];

        foreach ($clusters as $cluster) {
            $all += $this->index($client->listVms([$cluster->moref]));
        }

        return $all;
    }

    /**
     * Mapa masina po hostu, klasteru i datacentru — izvedena iz filtriranih
     * listi umesto iz poziva po masini.
     *
     * @param list<HostDto> $hosts
     * @param list<ClusterDto> $clusters
     * @return array<string, array{host: ?string, cluster: ?string, folder: ?string, datacenter: ?string}>
     */
    private function placementByHost(ClientInterface $client, array $hosts, array $clusters): array
    {
        $clusterToDc = [];

        foreach ($clusters as $cluster) {
            $clusterToDc[$cluster->moref] = $cluster->datacenterMoref;
        }

        $placement = [];

        foreach ($hosts as $host) {
            try {
                $onHost = $this->index($client->listVmsOnHost($host->moref));
            } catch (VCenterException $e) {
                // Host koji ne odgovara nije razlog da se odustane od ostalih;
                // njegove masine ce samo ostati bez podatka o smestaju.
                $this->failures[] = 'smestaj za host ' . $host->moref . ': ' . $e->getMessage();
                continue;
            }

            foreach (array_keys($onHost) as $moref) {
                $placement[$moref] = [
                    'host'       => $host->moref,
                    'cluster'    => $host->clusterMoref,
                    'folder'     => null,
                    'datacenter' => $host->datacenterMoref
                        ?? ($host->clusterMoref !== null ? ($clusterToDc[$host->clusterMoref] ?? null) : null),
                ];
            }
        }

        return $placement;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function index(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $moref = (string) ($row['vm'] ?? '');

            if ($moref !== '') {
                $indexed[$moref] = $row;
            }
        }

        return $indexed;
    }
}
