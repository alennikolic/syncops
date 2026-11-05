<?php
// src/Storage/Maintenance.php
//
// Praznjenje prikupljenih podataka, uz cuvanje podesavanja.
//
// Cemu sluzi: u razvoju se sema i maperi menjaju, pa inventar nakupi redove
// koji su nastali po starim pravilima. Umesto brisanja cele baze i ponovnog
// podesavanja vCentera, ovo obrise samo ono sto prikupljanje ume ponovo da
// napravi.
//
// Sta OSTAJE: integration (sa sifrovanim lozinkama) i schema_migrations.
// Sta se BRISE: sve ostalo — inventar, journali, sirovi zapisi, istorija
// prikupljanja.
//
// Nove tabele se brisu podrazumevano. To je namerno: kada se doda sloj za
// CMDB, i njegovi podaci su izvedeni, pa treba da nestanu zajedno sa ostalim.
// Tabela koja treba da prezivi dopisuje se u PRESERVED.
//
// TRUNCATE a ne DELETE: vraca i brojace na pocetak, pa posle ciscenja prvo
// prikupljanje opet nosi broj 1. Posto TRUNCATE ne radi nad tabelom na koju
// pokazuje strani kljuc, provera kljuceva se privremeno iskljucuje — zato je
// vazno da se obrisu SVE tabele koje ucestvuju u tim vezama, a ne neke.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;
use Throwable;

final class Maintenance
{
    /** Tabele koje ciscenje ne dira. */
    public const PRESERVED = ['integration', 'schema_migrations'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tabele koje bi ciscenje obrisalo, sa brojem redova.
     *
     * Sluzi da se pre brisanja vidi tacno sta odlazi — bez toga je dugme
     * "obrisi sve" obecanje na rec.
     *
     * @return array<string, int> ime tabele => broj redova
     */
    public function clearable(): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $counts[$table] = (int) $this->pdo
                ->query('SELECT COUNT(*) FROM `' . $table . '`')
                ->fetchColumn();
        }

        return $counts;
    }

    /**
     * Obrisi prikupljene podatke. Vraca broj obrisanih redova po tabeli.
     *
     * @return array<string, int>
     */
    public function clearCollected(): array
    {
        $before = $this->clearable();

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (array_keys($before) as $table) {
                $this->pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            // I kada neki TRUNCATE pukne, provera kljuceva mora nazad —
            // inace bi ostatak sesije pisao u bazu bez ijedne zastite.
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Integracije ostaju, ali podatak o poslednjem prikupljanju vise ne
        // stoji — istorija run-ova je obrisana, pa bi datum bez nje zbunjivao.
        $this->pdo->exec(
            'UPDATE integration
                SET last_tested_at    = NULL,
                    last_test_ok      = NULL,
                    last_test_message = NULL,
                    updated_at        = UTC_TIMESTAMP()'
        );

        return $before;
    }

    /**
     * Ima li posla koji je u toku. Ciscenje usred prikupljanja bi workeru
     * izmaklo tabele ispod ruku, pa se tada odbija.
     */
    public function hasRunningJob(): bool
    {
        try {
            return (bool) $this->pdo
                ->query("SELECT COUNT(*) FROM discovery_run WHERE status IN ('queued','running')")
                ->fetchColumn();
        } catch (Throwable) {
            // Tabela jos ne postoji — znaci da ni posla nema.
            return false;
        }
    }

    /**
     * Sve tabele tekuce baze osim sacuvanih, redom kojim se brisu.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE   = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        );

        /** @var list<string> $all */
        $all = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            $all,
            static fn (string $t): bool => !in_array($t, self::PRESERVED, true)
        ));
    }
}
