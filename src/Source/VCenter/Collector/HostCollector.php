<?php
// src/Source/VCenter/Collector/HostCollector.php
//
// Prikuplja ESXi hostove.
//
// Pripadnost klasteru se saznaje tako sto se lista trazi PO KLASTERU — API je
// ne vraca u odgovoru. Posle toga se trazi i puna lista po datacentru, da se
// pokupe samostalni hostovi (oni koji nisu ni u jednom klasteru).
//
// Cena: jedan poziv po klasteru + jedan po datacentru.
//
// Hardverski podaci se traze preko klijenta; REST vraca null, pa ostaju prazni
// i hardware_source ostaje NULL. Kada stigne SOAP klijent, isti ovaj kod ce ih
// popuniti bez izmene.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\HostDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\HostMapper;

final class HostCollector
{
    public function __construct(
        private readonly HostMapper $mapper = new HostMapper(),
    ) {
    }

    /**
     * @param list<\SyncOps\Source\Dto\ClusterDto> $clusters
     * @param array<string, string> $datacenters moref => ime
     * @return list<HostDto>
     */
    public function collect(ClientInterface $client, array $clusters, array $datacenters): array
    {
        /** @var array<string, HostDto> $byMoref */
        $byMoref = [];

        // 1) Hostovi u klasterima
        foreach ($clusters as $cluster) {
            foreach ($client->listHosts([$cluster->moref]) as $row) {
                $moref = (string) ($row['host'] ?? '');

                if ($moref === '') {
                    continue;
                }

                $byMoref[$moref] = $this->mapper->map($row, $cluster->moref, $cluster->datacenterMoref);
            }
        }

        // 2) Samostalni hostovi — puna lista po datacentru, pa sve sto vec nije
        //    vidjeno kroz neki klaster.
        foreach ($datacenters as $dcMoref => $dcName) {
            foreach ($client->listHosts([], [$dcMoref]) as $row) {
                $moref = (string) ($row['host'] ?? '');

                if ($moref === '' || isset($byMoref[$moref])) {
                    continue;
                }

                $byMoref[$moref] = $this->mapper->map($row, null, $dcMoref);
            }
        }

        // 3) Hardver, ako ga izvor uopste daje
        foreach ($byMoref as $moref => $host) {
            $hw = $client->getHostHardware($moref);

            if ($hw !== null) {
                $byMoref[$moref] = $this->mapper->withHardware($host, $hw, HostDto::SOURCE_SOAP);
            }
        }

        return array_values($byMoref);
    }
}
