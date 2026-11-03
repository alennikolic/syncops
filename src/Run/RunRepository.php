<?php
// src/Run/RunRepository.php
//
// Evidencija discovery poslova.
//
// Zakljucavanje radi sama baza: generisana kolona active_lock drzi
// integration_id samo dok je run u stanju queued ili running, a nad njom stoji
// UNIQUE indeks. Pokusaj da se otvori drugi run nad istom integracijom zato
// pada na duplikatu kljuca — i to je ispravno ponasanje, ne greska.

declare(strict_types=1);

namespace SyncOps\Run;

use PDO;
use PDOException;
use RuntimeException;

final class RunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Otvori nov run.
     *
     * @throws RuntimeException ako nad ovom integracijom vec tece neki run
     */
    public function create(int $integrationId, string $trigger = RunStatus::TRIGGER_CLI): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO discovery_run (integration_id, status, trigger_source, queued_at)
                 VALUES (:id, :status, :trigger, UTC_TIMESTAMP())'
            );

            $stmt->execute([
                'id'      => $integrationId,
                'status'  => RunStatus::QUEUED,
                'trigger' => $trigger,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // 23000 = narusen integritet; ovde to znaci sudar na active_lock.
            if ($e->getCode() === '23000') {
                throw new RuntimeException(
                    'Nad ovom integracijom vec tece prikupljanje. '
                    . 'Sacekaj da se zavrsi ili ga oznaci kao neuspelo.',
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Oznaci run kao pokrenut.
     *
     * started_at se postavlja SAMO ako jos nije postavljen: ova metoda se zove
     * i drugi put, da upise prepoznati dijalekt, pa bi bez COALESCE pomerila
     * pocetak run-a na trenutak kada je prikupljanje vec bilo gotovo — i
     * trajanje run-a bi u bazi bilo besmisleno.
     */
    public function markRunning(int $runId, ?string $flavor = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = :status,
                    started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                    heartbeat_at = UTC_TIMESTAMP(),
                    api_flavor = COALESCE(:flavor, api_flavor)
              WHERE id = :id'
        );

        $stmt->execute(['status' => RunStatus::RUNNING, 'flavor' => $flavor, 'id' => $runId]);
    }

    /**
     * Znak zivota. Bez ovoga se run koji je pukao zajedno sa workerom ne
     * razlikuje od onog koji jos radi.
     */
    public function heartbeat(int $runId, int $apiCalls = 0): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET heartbeat_at = UTC_TIMESTAMP(), api_calls = :calls
              WHERE id = :id'
        );

        $stmt->execute(['calls' => $apiCalls, 'id' => $runId]);
    }

    /**
     * Ishod za jedan tip objekta. Ovo je ono sto status "partial" cini
     * upotrebljivim: vidi se da su masine prosle a hostovi pali.
     */
    public function recordType(
        int $runId,
        string $type,
        string $status,
        int $found = 0,
        int $changed = 0,
    ): void {
        if (!in_array($type, ['clusters', 'hosts', 'vms'], true)) {
            throw new RuntimeException("Nepoznat tip objekta: {$type}");
        }

        // Imena kolona dolaze iz provere iznad, nikada iz korisnickog unosa.
        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE discovery_run
                SET %s_status = :status, %s_found = :found, %s_changed = :changed,
                    heartbeat_at = UTC_TIMESTAMP()
              WHERE id = :id',
            $type,
            $type,
            $type
        ));

        $stmt->execute([
            'status'  => $status,
            'found'   => $found,
            'changed' => $changed,
            'id'      => $runId,
        ]);
    }

    public function finish(int $runId, string $status, ?string $error = null, int $apiCalls = 0): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = :status,
                    finished_at = UTC_TIMESTAMP(),
                    error_message = :error,
                    api_calls = :calls
              WHERE id = :id'
        );

        $stmt->execute([
            'status' => $status,
            'error'  => $error,
            'calls'  => $apiCalls,
            'id'     => $runId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_run WHERE id = :id');
        $stmt->execute(['id' => $runId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Run koji je ostao u stanju running a worker mu je odavno umro.
     * Koristi ga sledeci run da oslobodi zakljucanu integraciju.
     *
     * @return list<array<string, mixed>>
     */
    public function stale(int $olderThanMinutes = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discovery_run
              WHERE status IN (:queued, :running)
                AND COALESCE(heartbeat_at, queued_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)'
        );

        $stmt->bindValue('queued', RunStatus::QUEUED);
        $stmt->bindValue('running', RunStatus::RUNNING);
        $stmt->bindValue('minutes', $olderThanMinutes, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
