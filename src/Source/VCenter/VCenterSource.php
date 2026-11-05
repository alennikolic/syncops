<?php
// src/Source/VCenter/VCenterSource.php
//
// Orkestrira prikupljanje sa jednog vCentera: prijava, tri kolektora, odjava.
//
// Ne dira bazu. Vraca normalizovane objekte; upis je posao sloja iznad, da bi
// prikupljanje moglo da se pokrene i kao suva proba, bez posledica.

declare(strict_types=1);

namespace SyncOps\Source\VCenter;

use SensitiveParameter;
use SyncOps\Integration\Integration;
use SyncOps\Source\VCenter\Client\ClientFactory;
use SyncOps\Source\VCenter\Collector\ClusterCollector;
use SyncOps\Source\VCenter\Collector\HostCollector;
use SyncOps\Source\VCenter\Collector\VmCollector;

final class VCenterSource
{
    public function __construct(
        private readonly ClientFactory $factory,
        private readonly ClusterCollector $clusters = new ClusterCollector(),
        private readonly HostCollector $hosts = new HostCollector(),
        private readonly VmCollector $vms = new VmCollector(),
    ) {
    }

    /**
     * Lozinka se prosledjuje spolja, a ne cita iz repozitorijuma: ovoj klasi
     * treba samo lozinka, ne i pristup bazi.
     *
     * @param callable(string, int, int): void|null $progress
     *        (faza, uradjeno, ukupno) — za ispis toka posla
     */
    public function collect(
        Integration $integration,
        #[SensitiveParameter] string $password,
        ?callable $progress = null,
    ): CollectionResult {
        $started = microtime(true);
        $client  = null;

        try {
            $client = $this->factory->connect($integration, $password);

            $notify = static function (string $phase, int $done, int $total) use ($progress): void {
                if ($progress !== null) {
                    $progress($phase, $done, $total);
                }
            };

            $notify('klasteri', 0, 0);
            $found = $this->clusters->collect($client);

            $notify('hostovi', 0, 0);
            $hosts = $this->hosts->collect($client, $found['clusters'], $found['datacenters']);

            $vms = $this->vms->collect(
                $client,
                $found['clusters'],
                $hosts,
                $progress === null
                    ? null
                    : static function (int $done, int $total) use ($notify): void {
                        $notify('masine', $done, $total);
                    },
            );

            return new CollectionResult(
                flavor: $client->flavor(),
                productVersion: $client->productVersion(),
                clusters: $found['clusters'],
                hosts: $hosts,
                vms: $vms,
                failures: $this->vms->failures(),
                // Jedan protokol znaci i jedno mesto na kom nesto moze da zakaze:
                // ili se inventar procitao, ili je run pukao. Dopunski
                // izvor koji moze tiho da izostane vise ne postoji.
                warnings: [],
                apiCalls: $client->callCount(),
                durationSeconds: microtime(true) - $started,
            );
        } finally {
            // vCenter ima limit konkurentnih sesija: odjava i kada je puklo.
            $client?->logout();
        }
    }
}
