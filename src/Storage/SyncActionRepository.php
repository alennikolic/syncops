<?php
// src/Storage/SyncActionRepository.php
//
// Dnevnik upisa u AssetOPS.
//
// Zapis o ucinjenom, ne red cekanja: red nastaje tek kada se poziv posalje.
// Predlog zivi na ekranu i racuna se iznova, a cuva se samo ono sto se
// stvarno dogodilo — i uspeh i neuspeh.
//
// Bez ovoga, posle prolaza koji je pao na pola ne bi se znalo ni sta je
// proslo ni sta treba ponoviti.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;

final class SyncActionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Upisi jedan pokusaj.
     *
     * Ime objekta se pamti onakvo kakvo je bilo u trenutku upisa: masina
     * kasnije moze da nestane iz inventara, a dnevnik mora da ostane citljiv
     * i tada.
     *
     * @param array<string, mixed>      $action
     * @param array<string, mixed>|null $payload
     */
    public function record(
        int $integrationId,
        string $sourceKind,
        array $action,
        string $kind,
        string $status,
        ?int $httpStatus,
        string $message,
        ?array $payload,
        ?string $ciUuid,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_action
                (integration_id, source_kind, source_id, source_name, ci_uuid,
                 action, status, http_status, message, payload, created_at)
             VALUES
                (:i, :k, :source, :name, :uuid, :action, :status, :http, :message, :payload, UTC_TIMESTAMP())'
        );

        $stmt->execute([
            'i'       => $integrationId,
            'k'       => $sourceKind,
            'source'  => (int) ($action['source']['id'] ?? 0),
            'name'    => (string) ($action['source']['name'] ?? ''),
            'uuid'    => $ciUuid,
            'action'  => $kind,
            'status'  => $status,
            'http'    => $httpStatus,
            'message' => $message === '' ? null : $message,
            'payload' => $payload === null
                ? null
                : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Da li je ikada nesto uspesno upisano.
     *
     * Od ovoga zavisi ogranicenje prvog prolaza. Namerno gleda samo
     * uspesne: prolaz u kom je sve palo nije „prvi upis je obavljen".
     */
    public function hasApplied(int $integrationId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sync_action
              WHERE integration_id = :i AND status = 'applied'
              LIMIT 1"
        );
        $stmt->execute(['i' => $integrationId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Poslednji upisi, najnoviji prvi.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $integrationId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_action
              WHERE integration_id = :i
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute(['i' => $integrationId]);

        return $stmt->fetchAll();
    }

    /**
     * Brojevi po ishodu, za sazetak.
     *
     * @return array<string, int>
     */
    public function counts(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM sync_action
              WHERE integration_id = :i GROUP BY status'
        );
        $stmt->execute(['i' => $integrationId]);

        $byStatus = ['applied' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($stmt->fetchAll() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }

        return $byStatus;
    }
}
