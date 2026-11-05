<?php
// src/Storage/SchemaQuery.php
//
// Citanje sopstvene seme iz information_schema, za stranicu pregleda baze.
//
// Namena je dijagnosticka: videti koje tabele postoje, kakve su im kolone i
// sta tacno stoji u redovima, bez formatiranja koje radi ostatak GUI-ja.
//
// Bezbednost — tri pravila koja se ne smeju prekrsiti:
//
//  1. Ime tabele nikad ne ulazi u upit dok se ne nadje u spisku tabela koji
//     je sama baza vratila. Korisnik ne moze da ponudi ime koje ne postoji,
//     pa ni da ubaci svoj SQL. Posle provere se ime jos i navodi backtick-ovima.
//  2. Kolone iz HIDDEN se ne citaju uopste — ne samo da se ne prikazuju.
//     Sifrovana lozinka vCenter naloga nema razloga da putuje do pregledaca.
//  3. Nema slobodnog unosa SQL-a. Stranica ume da cita tabelu i red, nista
//     vise. SQL konzola u web GUI-ju je prevelik rizik za dobijenu korist.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;

final class SchemaQuery
{
    public const PER_PAGE = 50;

    /**
     * Kolone koje se nikad ne citaju iz baze.
     *
     * Kljuc je "tabela.kolona", vrednost je objasnjenje koje se prikazuje
     * umesto sadrzaja, da bi se videlo da kolona postoji.
     */
    private const HIDDEN = [
        'integration.password_encrypted' => 'šifrovana lozinka vCenter naloga',
    ];

    /** Tipovi cija se vrednost prikazuje kao duzina, ne kao sadrzaj. */
    private const BINARY_TYPES = [
        'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary',
    ];

    /** @var list<string>|null Spisak tabela, ucitan najvise jednom po zahtevu */
    private ?array $tableNames = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -----------------------------------------------------------------
    // Tabele
    // -----------------------------------------------------------------

    /**
     * Sve tabele u tekucoj bazi, sa stvarnim brojem redova.
     *
     * TABLE_ROWS iz information_schema se namerno ne koristi: za InnoDB je
     * to procena iz statistike i ume da promasi za desetine procenata. Ovde
     * ih ima desetak, pa COUNT(*) po tabeli nista ne kosta.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME               AS name,
                    TABLE_COMMENT            AS comment,
                    ENGINE                   AS engine,
                    TABLE_COLLATION          AS collation_name,
                    DATA_LENGTH              AS data_bytes,
                    INDEX_LENGTH             AS index_bytes
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE   = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        );

        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as &$table) {
            $table['rows'] = $this->countRows((string) $table['name']);
        }

        return $tables;
    }

    public function databaseName(): string
    {
        return (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * Postoji li tabela pod tim imenom u tekucoj bazi.
     *
     * Ovo je jedina kapija kroz koju ime tabele sme da prodje do upita.
     */
    public function exists(string $table): bool
    {
        if ($this->tableNames === null) {
            $stmt = $this->pdo->query(
                "SELECT TABLE_NAME
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_TYPE   = 'BASE TABLE'"
            );

            /** @var list<string> $names */
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $this->tableNames = $names;
        }

        return in_array($table, $this->tableNames, true);
    }

    public function countRows(string $table): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table))
            ->fetchColumn();
    }

    // -----------------------------------------------------------------
    // Kolone i indeksi
    // -----------------------------------------------------------------

    /**
     * Opis kolona, redom kako su definisane.
     *
     * Uz podatke iz information_schema dodaju se dve oznake koje prikaz
     * koristi: da li je kolona sakrivena i da li je binarna.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME     AS name,
                    COLUMN_TYPE     AS type,
                    DATA_TYPE       AS data_type,
                    IS_NULLABLE     AS nullable,
                    COLUMN_KEY      AS key_type,
                    COLUMN_DEFAULT  AS default_value,
                    EXTRA           AS extra,
                    COLUMN_COMMENT  AS comment
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = :t
              ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['t' => $table]);

        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as &$column) {
            $name = (string) $column['name'];

            $column['hidden']        = isset(self::HIDDEN[$table . '.' . $name]);
            $column['hidden_reason'] = self::HIDDEN[$table . '.' . $name] ?? null;
            $column['binary']        = in_array(
                strtolower((string) $column['data_type']),
                self::BINARY_TYPES,
                true
            );
        }

        return $columns;
    }

    /**
     * Indeksi, grupisani po imenu, sa kolonama u redosledu u kom su u indeksu.
     *
     * @return list<array{name: string, unique: bool, type: string, columns: list<string>}>
     */
    public function indexes(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT INDEX_NAME                            AS name,
                    NON_UNIQUE                            AS non_unique,
                    INDEX_TYPE                            AS type,
                    COALESCE(COLUMN_NAME, EXPRESSION)     AS column_name
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = :t
              ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute(['t' => $table]);

        $grouped = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['name'];

            $grouped[$name] ??= [
                'name'    => $name,
                'unique'  => (int) $row['non_unique'] === 0,
                'type'    => (string) $row['type'],
                'columns' => [],
            ];

            $grouped[$name]['columns'][] = (string) $row['column_name'];
        }

        return array_values($grouped);
    }

    /**
     * Strani kljucevi koji polaze iz ove tabele.
     *
     * @return list<array<string, mixed>>
     */
    public function foreignKeys(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT k.CONSTRAINT_NAME       AS name,
                    k.COLUMN_NAME           AS column_name,
                    k.REFERENCED_TABLE_NAME AS ref_table,
                    k.REFERENCED_COLUMN_NAME AS ref_column,
                    r.DELETE_RULE           AS on_delete,
                    r.UPDATE_RULE           AS on_update
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.TABLE_NAME   = :t
                AND k.REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $stmt->execute(['t' => $table]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ime primarnog kljuca, ako je jednokolonski.
     *
     * Slozen primarni kljuc vraca null — tada se po redu ne moze linkovati
     * jednim parametrom, pa se detalji reda ne nude.
     */
    public function primaryKey(string $table): ?string
    {
        $keys = [];

        foreach ($this->columns($table) as $column) {
            if ($column['key_type'] === 'PRI') {
                $keys[] = (string) $column['name'];
            }
        }

        return count($keys) === 1 ? $keys[0] : null;
    }

    // -----------------------------------------------------------------
    // Redovi
    // -----------------------------------------------------------------

    /**
     * Strana sirovih redova.
     *
     * Redosled je po primarnom kljucu opadajuce — najnovije prvo, sto je
     * ono sto covek trazi kad otvori tabelu posle prikupljanja. Bez
     * jednokolonskog kljuca nema ORDER BY, pa redosled odredjuje baza.
     *
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public function rows(string $table, int $page = 1): array
    {
        $total = $this->countRows($table);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($page, $pages));

        $pk    = $this->primaryKey($table);
        $order = $pk === null ? '' : ' ORDER BY ' . $this->quoteIdentifier($pk) . ' DESC';

        $sql = 'SELECT ' . $this->selectList($table)
             . ' FROM ' . $this->quoteIdentifier($table)
             . $order
             . ' LIMIT ' . self::PER_PAGE
             . ' OFFSET ' . (($page - 1) * self::PER_PAGE);

        return [
            'rows'  => $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Jedan red po vrednosti primarnog kljuca.
     *
     * @return array<string, mixed>|null
     */
    public function row(string $table, string $id): ?array
    {
        $pk = $this->primaryKey($table);

        if ($pk === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->selectList($table)
            . ' FROM ' . $this->quoteIdentifier($table)
            . ' WHERE ' . $this->quoteIdentifier($pk) . ' = :id'
            . ' LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // -----------------------------------------------------------------
    // Interno
    // -----------------------------------------------------------------

    /**
     * Spisak kolona za SELECT, bez sakrivenih.
     *
     * Sakrivena kolona se ne cita iz baze uopste. Da se samo ne prikazuje,
     * njen sadrzaj bi svejedno prosao kroz PHP i mogao da zavrsi u logu
     * greske ili u dumpu promenljivih.
     */
    private function selectList(string $table): string
    {
        $names = [];

        foreach ($this->columns($table) as $column) {
            if ($column['hidden'] === true) {
                continue;
            }

            $names[] = $this->quoteIdentifier((string) $column['name']);
        }

        return $names === [] ? '*' : implode(', ', $names);
    }

    /**
     * Navodjenje identifikatora.
     *
     * Ime tabele je vec provereno kroz exists(), a imena kolona dolaze iz
     * information_schema — dakle iz same baze, ne od korisnika. Backtick-ovi
     * su druga brava na istim vratima, za slucaj da se prva jednom zaboravi.
     */
    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
