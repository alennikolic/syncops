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
use SyncOps\Storage\Repository\HostRepository;
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
    ) {
    }

    /**
     * @param callable(string, int, int): void|null $progress
     */
    public function run(
        Integration $integration,
        string $trigger = RunStatus::TRIGGER_CLI,
        ?callable $progress = null,
    ): RunOutcome {
        $runId = $this->runs->create($integration->id, $trigger);

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

            $this->runs->finish(
                $runId,
                $status,
                $result->failures === [] ? null : implode("\n", array_slice($result->failures, 0, 50)),
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
