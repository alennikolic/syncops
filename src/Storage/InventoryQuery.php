<?php
// src/Storage/InventoryQuery.php
//
// Citanje inventara za prikaz. Odvojeno od InventoryRepository, koji samo pise:
// upis radi sa DTO-ima i hesovima, citanje sa filterima i strranicenjem.
//
// Nazivi hostova i klastera se dovlace spajanjem po moref-u unutar iste
// integracije — moref je jedinstven samo u okviru svog vCentera, pa uslov
// spajanja UVEK sadrzi i integration_id.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;

final class InventoryQuery
{
    public const PER_PAGE = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -----------------------------------------------------------------
    // Masine
    // -----------------------------------------------------------------

    /**
     * @param array{search?: string, power?: string, cluster?: string, host?: string, guest?: string, integration?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function vms(array $filters = [], int $page = 1): array
    {
        [$where, $params] = $this->vmFilters($filters);

        $total = $this->count('vc_vm v', $where, $params);
        $page  = $this->clampPage($page, $total);

        $sql = 'SELECT v.*,
                       h.name AS host_name,
                       c.name AS cluster_name,
                       i.name AS integration_name
                  FROM vc_vm v
                  LEFT JOIN vc_host    h ON h.integration_id = v.integration_id AND h.moref = v.host_moref
                  LEFT JOIN vc_cluster c ON c.integration_id = v.integration_id AND c.moref = v.cluster_moref
                  JOIN integration     i ON i.id = v.integration_id
                 WHERE ' . $where . '
                 ORDER BY v.name
                 LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE);

        return $this->paged($sql, $params, $total, $page);
    }

    /**
     * Masine za izvoz: isti filteri, bez stranicenja.
     *
     * Vraca generator a ne niz, da se izvoz od deset hiljada redova ne sklapa
     * ceo u memoriji pre nego sto krene ka pregledacu.
     *
     * @param array{search?: string, power?: string, cluster?: string, host?: string, guest?: string, integration?: int} $filters
     * @return iterable<array<string, mixed>>
     */
    public function vmsForExport(array $filters = []): iterable
    {
        [$where, $params] = $this->vmFilters($filters);

        $stmt = $this->pdo->prepare(
            'SELECT v.*,
                    h.name AS host_name,
                    c.name AS cluster_name,
                    i.name AS integration_name
               FROM vc_vm v
               LEFT JOIN vc_host    h ON h.integration_id = v.integration_id AND h.moref = v.host_moref
               LEFT JOIN vc_cluster c ON c.integration_id = v.integration_id AND c.moref = v.cluster_moref
               JOIN integration     i ON i.id = v.integration_id
              WHERE ' . $where . '
              ORDER BY i.name, v.name'
        );

        $stmt->execute($params);

        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vm(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*,
                    h.name AS host_name,
                    c.name AS cluster_name,
                    i.name AS integration_name,
                    i.id   AS integration_id
               FROM vc_vm v
               LEFT JOIN vc_host    h ON h.integration_id = v.integration_id AND h.moref = v.host_moref
               LEFT JOIN vc_cluster c ON c.integration_id = v.integration_id AND c.moref = v.cluster_moref
               JOIN integration     i ON i.id = v.integration_id
              WHERE v.id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Journal promena nad jednom masinom, najnovije prvo.
     *
     * @return list<array<string, mixed>>
     */
    public function vmChanges(int $vmId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_vm_change WHERE vm_id = :id ORDER BY changed_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute(['id' => $vmId]);

        return $stmt->fetchAll();
    }

    // -----------------------------------------------------------------
    // Hostovi
    // -----------------------------------------------------------------

    /**
     * @param array{search?: string, integration?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function hosts(array $filters = [], int $page = 1): array
    {
        [$where, $params] = $this->hostFilters($filters);

        $total = $this->count('vc_host h', $where, $params);
        $page  = $this->clampPage($page, $total);

        $sql = 'SELECT h.*,
                       c.name AS cluster_name,
                       i.name AS integration_name,
                       (SELECT COUNT(*) FROM vc_vm v
                         WHERE v.integration_id = h.integration_id AND v.host_moref = h.moref) AS vm_count
                  FROM vc_host h
                  LEFT JOIN vc_cluster c ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
                  JOIN integration     i ON i.id = h.integration_id
                 WHERE ' . $where . '
                 ORDER BY h.name
                 LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE);

        return $this->paged($sql, $params, $total, $page);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function hostFilters(array $filters): array
    {
        $where  = '1 = 1';
        $params = [];

        if (($filters['search'] ?? '') !== '') {
            $where .= $this->searchClause([
                'h.name',
                'h.serial_number',
                'h.model',
                'h.vendor',
                'h.hardware_uuid',
                'h.moref',
            ], (string) $filters['search'], $params);
        }

        if (($filters['state'] ?? '') !== '') {
            $where .= ' AND h.connection_state = :state';
            $params['state'] = $filters['state'];
        }

        if (($filters['cluster'] ?? '') !== '') {
            $where .= ' AND h.cluster_moref = :cluster';
            $params['cluster'] = $filters['cluster'];
        }

        if (($filters['integration'] ?? 0) > 0) {
            $where .= ' AND h.integration_id = :integration';
            $params['integration'] = (int) $filters['integration'];
        }

        return [$where, $params];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function host(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*,
                    c.name AS cluster_name,
                    i.name AS integration_name,
                    (SELECT COUNT(*) FROM vc_vm v
                      WHERE v.integration_id = h.integration_id AND v.host_moref = h.moref) AS vm_count
               FROM vc_host h
               LEFT JOIN vc_cluster c ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
               JOIN integration     i ON i.id = h.integration_id
              WHERE h.id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Masine koje sede na jednom hostu — ono sto se gleda pred odrzavanje.
     *
     * @return list<array<string, mixed>>
     */
    public function vmsOnHost(int $integrationId, string $hostMoref): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, power_state, cpu_count, memory_mib, guest_ip_address
               FROM vc_vm
              WHERE integration_id = :integration AND host_moref = :moref
              ORDER BY name'
        );
        $stmt->execute(['integration' => $integrationId, 'moref' => $hostMoref]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hostChanges(int $hostId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_host_change WHERE host_id = :id ORDER BY changed_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute(['id' => $hostId]);

        return $stmt->fetchAll();
    }

    /**
     * Hostovi za izvoz: isti filteri, bez stranicenja.
     *
     * @param array<string, mixed> $filters
     * @return iterable<array<string, mixed>>
     */
    public function hostsForExport(array $filters = []): iterable
    {
        [$where, $params] = $this->hostFilters($filters);

        $stmt = $this->pdo->prepare(
            'SELECT h.*,
                    c.name AS cluster_name,
                    i.name AS integration_name,
                    (SELECT COUNT(*) FROM vc_vm v
                      WHERE v.integration_id = h.integration_id AND v.host_moref = h.moref) AS vm_count
               FROM vc_host h
               LEFT JOIN vc_cluster c ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
               JOIN integration     i ON i.id = h.integration_id
              WHERE ' . $where . '
              ORDER BY i.name, h.name'
        );

        $stmt->execute($params);

        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * Vrednosti za padajuce filtere na stranici hostova.
     *
     * @return array{clusters: list<array<string, mixed>>, states: list<string>}
     */
    public function hostFilterOptions(): array
    {
        return [
            'clusters' => $this->pdo->query(
                'SELECT DISTINCT c.moref, c.name
                   FROM vc_host h
                   JOIN vc_cluster c ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
                  ORDER BY c.name'
            )->fetchAll(),
            'states' => array_column(
                $this->pdo->query(
                    'SELECT DISTINCT connection_state FROM vc_host
                      WHERE connection_state IS NOT NULL ORDER BY connection_state'
                )->fetchAll(),
                'connection_state'
            ),
        ];
    }

    // -----------------------------------------------------------------
    // Klasteri
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function clusters(): array
    {
        return $this->pdo->query(
            'SELECT c.*,
                    i.name AS integration_name,
                    (SELECT COUNT(*) FROM vc_host h
                      WHERE h.integration_id = c.integration_id AND h.cluster_moref = c.moref) AS host_count,
                    (SELECT COUNT(*) FROM vc_vm v
                      WHERE v.integration_id = c.integration_id AND v.cluster_moref = c.moref) AS vm_count
               FROM vc_cluster c
               JOIN integration i ON i.id = c.integration_id
              ORDER BY i.name, c.name'
        )->fetchAll();
    }

    // -----------------------------------------------------------------
    // Pomocno
    // -----------------------------------------------------------------

    /**
     * Poslednji USPESAN run po integraciji.
     *
     * Sluzi da se u prikazu oznaci objekat koji nije vidjen u njemu — jedini
     * nagovestaj da je nesto nestalo iz vCentera. Delimicni i neuspeli run-ovi
     * se namerno ne racunaju: posle njih odsustvo objekta moze da znaci samo
     * to da prikupljanje nije stiglo do njega.
     *
     * @return array<int, int> integration_id => run_id
     */
    public function lastSuccessfulRuns(): array
    {
        $rows = $this->pdo->query(
            "SELECT integration_id, MAX(id) AS run_id
               FROM discovery_run
              WHERE status = 'success'
              GROUP BY integration_id"
        )->fetchAll();

        $byIntegration = [];

        foreach ($rows as $row) {
            $byIntegration[(int) $row['integration_id']] = (int) $row['run_id'];
        }

        return $byIntegration;
    }

    /**
     * Istorija sirovih odgovora za jedan objekat, najnoviji prvi.
     *
     * payload se ne vraca ceo za svaki red — jedan objekat ume da bude
     * nekoliko kilobajta, a istorija od deset prikupljanja bi onda bila
     * desetine kilobajta samo da bi se ispisala tabela sa datumima. Vraca
     * se duzina, pa prikaz nudi link na ceo zapis.
     *
     * @return list<array<string, mixed>>
     */
    public function rawHistory(int $integrationId, string $objectType, string $moref, int $limit = 15): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, run_id, collected_at, payload_hash,
                    CHAR_LENGTH(payload) AS payload_length
               FROM raw_object
              WHERE integration_id = :integration
                AND object_type    = :type
                AND moref          = :moref
              ORDER BY collected_at DESC, id DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([
            'integration' => $integrationId,
            'type'        => $objectType,
            'moref'       => $moref,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Ceo sirov odgovor jednog zapisa iz istorije.
     */
    public function rawPayload(int $rawObjectId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM raw_object WHERE id = :id');
        $stmt->execute(['id' => $rawObjectId]);

        $payload = $stmt->fetchColumn();

        return $payload === false ? null : (string) $payload;
    }

    /**
     * Vrednosti za padajuce filtere na stranici masina.
     *
     * @return array{clusters: list<array<string, mixed>>, hosts: list<array<string, mixed>>, powers: list<string>, guests: list<string>}
     */
    public function vmFilterOptions(): array
    {
        return [
            'clusters' => $this->pdo->query(
                'SELECT DISTINCT c.moref, c.name
                   FROM vc_vm v
                   JOIN vc_cluster c ON c.integration_id = v.integration_id AND c.moref = v.cluster_moref
                  ORDER BY c.name'
            )->fetchAll(),
            'hosts' => $this->pdo->query(
                'SELECT DISTINCT h.moref, h.name
                   FROM vc_vm v
                   JOIN vc_host h ON h.integration_id = v.integration_id AND h.moref = v.host_moref
                  ORDER BY h.name'
            )->fetchAll(),
            'powers' => array_column(
                $this->pdo->query('SELECT DISTINCT power_state FROM vc_vm WHERE power_state IS NOT NULL ORDER BY power_state')->fetchAll(),
                'power_state'
            ),
            'guests' => array_column(
                $this->pdo->query('SELECT DISTINCT guest_state FROM vc_vm WHERE guest_state IS NOT NULL ORDER BY guest_state')->fetchAll(),
                'guest_state'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function vmFilters(array $filters): array
    {
        $where  = '1 = 1';
        $params = [];

        if (($filters['search'] ?? '') !== '') {
            $where .= $this->searchClause([
                'v.name',
                'v.guest_hostname',
                'v.guest_ip_address',
                'v.instance_uuid',
                'v.bios_uuid',
                'v.moref',
                // Notes je cesto jedino mesto gde pise cemu masina sluzi,
                // pa je pretraga po njemu najkorisnija od svih.
                'v.notes',
            ], (string) $filters['search'], $params);
        }

        if (($filters['power'] ?? '') !== '') {
            $where .= ' AND v.power_state = :power';
            $params['power'] = $filters['power'];
        }

        if (($filters['guest'] ?? '') !== '') {
            $where .= ' AND v.guest_state = :guest';
            $params['guest'] = $filters['guest'];
        }

        if (($filters['cluster'] ?? '') !== '') {
            $where .= ' AND v.cluster_moref = :cluster';
            $params['cluster'] = $filters['cluster'];
        }

        if (($filters['host'] ?? '') !== '') {
            $where .= ' AND v.host_moref = :host';
            $params['host'] = $filters['host'];
        }

        if (($filters['integration'] ?? 0) > 0) {
            $where .= ' AND v.integration_id = :integration';
            $params['integration'] = (int) $filters['integration'];
        }

        return [$where, $params];
    }

    /**
     * Uslov pretrage preko vise kolona, sa ZASEBNIM parametrom po koloni.
     *
     * Zasto ne jedan :search ponovljen sest puta: veza se otvara sa
     * PDO::ATTR_EMULATE_PREPARES = false, pa upit ide pravom MySQL-ovom
     * pripremom. Tamo imenovani parametar sme da stoji samo na jednom mestu —
     * drugo pojavljivanje daje "SQLSTATE[HY093]: Invalid parameter number".
     * Sa emulacijom bi radilo, i upravo zato je ovo lako prevideti.
     *
     * @param list<string>         $columns Imena kolona, iskljucivo iz koda
     * @param array<string, mixed> $params  Dopunjuje se vrednostima
     */
    private function searchClause(array $columns, string $term, array &$params): string
    {
        $value      = '%' . $term . '%';
        $conditions = [];

        foreach ($columns as $i => $column) {
            $name = 'search_' . $i;

            $conditions[]  = $column . ' LIKE :' . $name;
            $params[$name] = $value;
        }

        return ' AND (' . implode(' OR ', $conditions) . ')';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $from, string $where, array $params): int
    {
        // Alias tabele u $from i uslov u $where dolaze iskljucivo iz koda.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function clampPage(int $page, int $total): int
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return max(1, min($page, $pages));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    private function paged(string $sql, array $params, int $total, int $page): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }
}
