<?php
// src/Source/VCenter/Collector/ClusterCollector.php
//
// Prikuplja klastere, po datacentru, da bi svaki klaster znao u kom je
// datacentru — API to ne vraca u samom odgovoru.
//
// Cena: jedan poziv za listu datacentara + jedan po datacentru.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\ClusterDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\ClusterMapper;

final class ClusterCollector
{
    public function __construct(
        private readonly ClusterMapper $mapper = new ClusterMapper(),
    ) {
    }

    /**
     * @return array{clusters: list<ClusterDto>, datacenters: array<string, string>}
     *         datacenters: moref => ime, koristi se dalje za hostove i masine
     */
    public function collect(ClientInterface $client): array
    {
        $datacenters = [];

        foreach ($client->listDatacenters() as $row) {
            $moref = (string) ($row['datacenter'] ?? '');

            if ($moref !== '') {
                $datacenters[$moref] = (string) ($row['name'] ?? '');
            }
        }

        $clusters = [];

        foreach ($datacenters as $dcMoref => $dcName) {
            foreach ($client->listClusters([$dcMoref]) as $row) {
                $clusters[] = $this->mapper->map($row, $dcMoref, $dcName);
            }
        }

        // vCenter bez ijednog datacentra ne bi trebalo da postoji, ali ako se
        // desi, klasteri se ipak pokupe — bez podatka o datacentru.
        if ($datacenters === []) {
            foreach ($client->listClusters() as $row) {
                $clusters[] = $this->mapper->map($row, null, null);
            }
        }

        return ['clusters' => $clusters, 'datacenters' => $datacenters];
    }
}
