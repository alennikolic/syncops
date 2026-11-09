<?php
// src/Run/DiscoveryRunner.php
//
// Spaja prikupljanje i upis: otvori run, pokupi sa vCentera, upisi u bazu,
// zatvori run sa ishodom.
//
// Transakcija je po tipu objekta, ne po redu i ne jedna velika:
//   - po redu bi znacilo 153 transakcije za masine
//   - jedna velika bi drzala zakljucane tabele kroz celo prikupljanje
// Ovako je svaki tip ili ceo upisan ili nijedan njegov red, a klasteri ostaju
// upisani i kada masine puknu — sto je i smisao statusa "partial".

declare(strict_types=1);

namespace SyncOps\Run;

use PDO;
use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\CollectionResult;
use SyncOps\Source\VCenter\VCenterSource;
use SyncOps\Storage\InventoryRepository;
use SyncOps\Storage\RawStore;
use SyncOps\Storage\Repository\ClusterRepository;
use SyncOps\Storage\Repository\DatastoreRepository;
use SyncOps\Storage\Repository\HostDeviceRepository;
use SyncOps\Storage\Repository\HostRepository;
use SyncOps\Storage\Repository\LicenseRepository;
use SyncOps\Storage\Repository\VmRepository;
use Throwable;

final class DiscoveryRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IntegrationRepository $integrations,
        private readonly RunRepository $runs,
        private readonly VCenterSource $source,
        private readonly RawStore $raw,
        private readonly ClusterRepository $clusters,
        private readonly HostRepository $hosts,
        private readonly VmRepository $vms,
        private readonly LicenseRepository $licenses,
        private readonly DatastoreRepository $datastores,
        private readonly HostDeviceRepository $hostDevices,
    ) {
    }

    /**
     * Stavi posao u red cekanja, bez izvrsavanja. Koristi GUI i zakazivanje:
     * prikupljanje ume da traje minutima i ne sme da ide kroz web zahtev.
     */
    public function enqueue(Integration $integration, string $trigger = RunStatus::TRIGGER_GUI): int
    {
        return $this->runs->create($integration->id, $trigger);
    }

    /**
     * Otvori posao i odmah ga izvrsi. Koristi CLI, kada se ceka ishod.
     *
     * @param callable(string, int, int): void|null $progress
     */
    public function run(
        Integration $integration,
        string $trigger = RunStatus::TRIGGER_CLI,
        ?callable $progress = null,
    ): RunOutcome {
        return $this->execute($this->runs->create($integration->id, $trigger), $integration, $progress);
    }

    /**
     * Izvrsi posao koji vec postoji u bazi — preuzet iz reda cekanja ili
     * upravo otvoren.
     *
     * @param callable(string, int, int): void|null $progress
     */
    public function execute(
        int $runId,
        Integration $integration,
        ?callable $progress = null,
    ): RunOutcome {
        try {
            $this->runs->markRunning($runId);

            $password = $this->integrations->password($integration->id);

            $result = $this->source->collect(
                $integration,
                $password,
                function (string $phase, int $done, int $total) use ($runId, $progress): void {
                    // Znak zivota na svakih 25 objekata, da heartbeat ne postane
                    // sam po sebi opterecenje za bazu.
                    if ($total > 0 && $done % 25 === 0) {
                        $this->runs->heartbeat($runId);
                    }

                    if ($progress !== null) {
                        $progress($phase, $done, $total);
                    }
                }
            );

            $this->runs->markRunning($runId, $result->flavor);

            $stats = $this->persist($integration->id, $runId, $result);

            $status = $result->isComplete() ? RunStatus::SUCCESS : RunStatus::PARTIAL;

            // Upozorenja se belezе i kada je run uspesan: hardverski podaci
            // koji su izostali treba da se vide u GUI-ju, ali ne smeju da
            // obore status — "partial" bi trajno ugasio oznaku
            // "nije vidjeno u poslednjem prikupljanju".
            $notes = array_merge(
                array_slice($result->failures, 0, 50),
                array_map(
                    static fn (string $w): string => 'Napomena: ' . $w,
                    $result->warnings
                )
            );

            $this->runs->finish(
                $runId,
                $status,
                $notes === [] ? null : implode("\n", $notes),
                $result->apiCalls
            );

            return new RunOutcome($runId, $status, $result, $stats);
        } catch (Throwable $e) {
            $this->runs->finish($runId, RunStatus::FAILED, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array<string, array{found: int, created: int, changed: int, unchanged: int}>
     */
    private function persist(int $integrationId, int $runId, CollectionResult $result): array
    {
        $plan = [
            'clusters' => [$this->clusters, 'cluster', $result->clusters],
            'hosts'    => [$this->hosts, 'host', $result->hosts],
            'vms'      => [$this->vms, 'vm', $result->vms],
            // Licence na kraju: ne zavise ni od cega iznad, a kada ih nalog
            // ne sme citati lista je prazna i persist() prolazi bez posla.
            'licenses' => [$this->licenses, 'license', $result->licenses],
            'datastores' => [$this->datastores, 'datastore', $result->datastores],
            'host_devices' => [$this->hostDevices, 'host_device', $result->hostDevices],
        ];

        $stats = [];

        foreach ($plan as $type => [$repository, $rawType, $objects]) {
            /** @var InventoryRepository $repository */
            $this->pdo->beginTransaction();

            try {
                $this->raw->store($runId, $integrationId, $rawType, $objects);
                $typeStats = $repository->persist($integrationId, $runId, $objects);

                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                $this->runs->recordType($runId, $type, RunStatus::TYPE_FAILED);

                throw $e;
            }

            $this->runs->recordType(
                $runId,
                $type,
                RunStatus::TYPE_OK,
                $typeStats['found'],
                $typeStats['created'] + $typeStats['changed']
            );

            $stats[$type] = $typeStats;
        }

        return $stats;
    }
}
