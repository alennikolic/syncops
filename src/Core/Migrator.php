<?php
// src/Core/Migrator.php
//
// Primenjuje SQL migracije iz db/migrations/ i vodi evidenciju u tabeli
// schema_migrations.
//
// Pravila:
//   - fajlovi se imenuju NNNN_naziv.sql (npr. 0001_integration.sql)
//   - primenjuju se rastucim redosledom broja verzije
//   - vec primenjena migracija se NE MENJA; ispravka ide kao nova verzija
//   - checksum svakog fajla se cuva, pa se naknadna izmena otkriva (drift)
//
// NAPOMENA o transakcijama: MySQL ne ume da povuce DDL unazad. Ako jedan
// fajl sadrzi vise naredbi i druga pukne, prva ostaje primenjena. Zato
// svaki fajl treba da nosi jednu logicku izmenu.

declare(strict_types=1);

namespace SyncOps\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dir,
    ) {
    }

    // -----------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------

    /**
     * Napravi tabelu schema_migrations ako ne postoji.
     *
     * Ova tabela nije migracija nad samom sobom — migrator je pravi sam,
     * jer bi je inace morao citati pre nego sto postoji.
     */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version     VARCHAR(20)  NOT NULL,
                name        VARCHAR(191) NOT NULL,
                checksum    CHAR(64)     NOT NULL COMMENT "SHA-256 sadrzaja fajla",
                applied_at  DATETIME     NOT NULL COMMENT "UTC",
                duration_ms INT UNSIGNED NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_0900_ai_ci
              COMMENT="Evidencija primenjenih migracija"'
        );
    }

    // -----------------------------------------------------------------
    // Citanje stanja
    // -----------------------------------------------------------------

    /**
     * Migracije koje postoje kao fajlovi, sortirane po verziji.
     *
     * @return array<string, array{version: string, name: string, path: string, checksum: string}>
     */
    public function available(): array
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException("Folder sa migracijama ne postoji: {$this->dir}");
        }

        $files = glob($this->dir . '/*.sql') ?: [];
        $found = [];

        foreach ($files as $path) {
            $basename = basename($path);

            if (!preg_match('/^(\d{4,})_([a-z0-9_]+)\.sql$/', $basename, $m)) {
                throw new RuntimeException(
                    "Nevazece ime migracije: {$basename}. Ocekivan format: NNNN_naziv.sql"
                );
            }

            [$version, $name] = [$m[1], $m[2]];

            if (isset($found[$version])) {
                throw new RuntimeException(
                    "Dve migracije imaju istu verziju {$version}: "
                    . basename($found[$version]['path']) . " i {$basename}"
                );
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Fajl se ne moze procitati: {$path}");
            }

            $found[$version] = [
                'version'  => $version,
                'name'     => $name,
                'path'     => $path,
                'checksum' => hash('sha256', $this->normalize($contents)),
            ];
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * Migracije koje su vec primenjene, po zapisu u bazi.
     *
     * @return array<string, array{version: string, name: string, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $rows = $this->pdo
            ->query('SELECT version, name, checksum, applied_at FROM ' . self::TABLE . ' ORDER BY version')
            ->fetchAll();

        $applied = [];

        foreach ($rows as $row) {
            $applied[(string) $row['version']] = $row;
        }

        return $applied;
    }

    /**
     * Migracije koje jos nisu primenjene.
     *
     * @return array<string, array{version: string, name: string, path: string, checksum: string}>
     */
    public function pending(): array
    {
        return array_diff_key($this->available(), $this->applied());
    }

    /**
     * Primenjene migracije cija se sadrzina u medjuvremenu promenila.
     *
     * Ovo je greska u radu, ne stanje koje migrator ume da popravi:
     * znaci da je neko izmenio fajl koji je vec odradjen, pa baze na
     * razlicitim masinama imaju istu oznaku verzije a razlicitu semu.
     *
     * @return list<array{version: string, name: string}>
     */
    public function drift(): array
    {
        $available = $this->available();
        $drift     = [];

        foreach ($this->applied() as $version => $row) {
            if (!isset($available[$version])) {
                // Fajl je obrisan, a migracija je primenjena.
                $drift[] = ['version' => $version, 'name' => $row['name'] . ' (fajl nedostaje)'];
                continue;
            }

            if (!hash_equals((string) $row['checksum'], $available[$version]['checksum'])) {
                $drift[] = ['version' => $version, 'name' => $available[$version]['name']];
            }
        }

        return $drift;
    }

    // -----------------------------------------------------------------
    // Primena
    // -----------------------------------------------------------------

    /**
     * Primeni jednu migraciju i upisi je u evidenciju.
     *
     * @param array{version: string, name: string, path: string, checksum: string} $migration
     * @return int Trajanje u milisekundama
     */
    public function apply(array $migration): int
    {
        $sql = file_get_contents($migration['path']);

        if ($sql === false) {
            throw new RuntimeException("Fajl se ne moze procitati: {$migration['path']}");
        }

        if (trim($sql) === '') {
            throw new RuntimeException("Migracija {$migration['version']} je prazna.");
        }

        $start = microtime(true);

        $this->pdo->exec($sql);

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (version, name, checksum, applied_at, duration_ms)
             VALUES (:version, :name, :checksum, UTC_TIMESTAMP(), :duration_ms)'
        );

        $stmt->execute([
            'version'     => $migration['version'],
            'name'        => $migration['name'],
            'checksum'    => $migration['checksum'],
            'duration_ms' => $durationMs,
        ]);

        return $durationMs;
    }

    // -----------------------------------------------------------------

    /**
     * Normalizuje sadrzaj pre racunanja checksum-a, da promena zavrsetka
     * reda (CRLF/LF) ili praznog reda na kraju ne bude prijavljena kao drift.
     */
    private function normalize(string $contents): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $contents)) . "\n";
    }
}
