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
     * $changes nosi izmene po polju (ref, from, to). Poslato telo kod izmene
     * je ceo procitani CI, pa se iz njega ne vidi sta je tacno dirnuto;
     * Planner tu razliku vec racuna, a do sada je nestajala sa upisom.
     *
     * @param array<string, mixed>      $action
     * @param array<string, mixed>|null $payload
     * @param list<array<string, mixed>>|null $changes
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
        ?array $changes = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_action
                (integration_id, source_kind, source_id, source_name, ci_uuid,
                 action, status, http_status, message, payload, changes, created_at)
             VALUES
                (:i, :k, :source, :name, :uuid, :action, :status, :http, :message,
                 :payload, :changes, UTC_TIMESTAMP())'
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
            'changes' => ($changes === null || $changes === [])
                ? null
                : (string) json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
     * Dnevnik sa filterima, za svoju stranicu.
     *
     * Prazan filter znaci „sve"; vrednost van dozvoljenog skupa se odbacuje
     * umesto da se ubaci u upit, jer stize iz adrese.
     *
     * @param array{status?: string, action?: string, kind?: string, q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function search(int $integrationId, array $filters = [], int $limit = 200, int $offset = 0): array
    {
        [$where, $params] = $this->conditions($integrationId, $filters);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_action
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Koliko redova odgovara filteru, bez ogranicenja.
     *
     * @param array{status?: string, action?: string, kind?: string, q?: string} $filters
     */
    public function countMatching(int $integrationId, array $filters = []): int
    {
        [$where, $params] = $this->conditions($integrationId, $filters);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sync_action WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Brojevi po ishodu i po vrsti akcije, za sazetak na vrhu.
     *
     * @return array{status: array<string, int>, action: array<string, int>,
     *               last: ?string, fields: int}
     */
    public function summary(int $integrationId): array
    {
        $byStatus = $this->counts($integrationId);

        $stmt = $this->pdo->prepare(
            'SELECT action, COUNT(*) AS n FROM sync_action
              WHERE integration_id = :i GROUP BY action'
        );
        $stmt->execute(['i' => $integrationId]);

        $byAction = ['create' => 0, 'update' => 0];

        foreach ($stmt->fetchAll() as $row) {
            $byAction[(string) $row['action']] = (int) $row['n'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT MAX(created_at) FROM sync_action WHERE integration_id = :i'
        );
        $stmt->execute(['i' => $integrationId]);
        $last = $stmt->fetchColumn();

        // Koliko je pojedinacnih polja izmenjeno ukupno. Broj zapisa ne kaze
        // koliko je posla obavljeno — jedna izmena ume da nosi jedno polje
        // ili deset.
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(JSON_LENGTH(changes)), 0) FROM sync_action
              WHERE integration_id = :i AND changes IS NOT NULL AND status = 'applied'"
        );
        $stmt->execute(['i' => $integrationId]);

        return [
            'status' => $byStatus,
            'action' => $byAction,
            'last'   => $last === false ? null : (string) $last,
            'fields' => (int) $stmt->fetchColumn(),
        ];
    }

    /**
     * Uslovi i parametri za filtere, na jednom mestu.
     *
     * @param array{status?: string, action?: string, kind?: string, q?: string} $filters
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function conditions(int $integrationId, array $filters): array
    {
        $where  = ['integration_id = :i'];
        $params = ['i' => $integrationId];

        $dozvoljeno = [
            'status' => ['applied', 'failed', 'skipped'],
            'action' => ['create', 'update'],
            'kind'   => ['vm', 'host'],
        ];

        foreach ($dozvoljeno as $kolona => $vrednosti) {
            $izbor = (string) ($filters[$kolona] ?? '');

            if (in_array($izbor, $vrednosti, true)) {
                $stupac  = $kolona === 'kind' ? 'source_kind' : $kolona;
                $where[] = "{$stupac} = :{$kolona}";

                $params[$kolona] = $izbor;
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            // Dva imena za istu vrednost: PDO sa ATTR_EMULATE_PREPARES =
            // false ne dozvoljava isti imenovani parametar dvaput u jednom
            // upitu.
            $where[]           = '(source_name LIKE :q_name OR ci_uuid LIKE :q_uuid)';
            $params['q_name']  = '%' . $q . '%';
            $params['q_uuid']  = '%' . $q . '%';
        }

        return [$where, $params];
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
