<?php
// src/Storage/InventoryRepository.php
//
// Upis jednog tipa objekta u inventory sloj, sa journalom promena.
//
// Napisano jednom za sva tri tipa: potomci samo kazu kako se tabele zovu.
// Logika je svuda ista jer svi DTO-i ispunjavaju InventoryDto.
//
// Postupak po objektu:
//   nema ga u bazi            -> INSERT, first_seen = last_seen = sada
//   ima ga, hash isti         -> UPDATE samo last_seen i last_run_id
//   ima ga, hash razlicit     -> UPDATE svih polja + red u *_change tabeli
//
// NIJEDAN RED SE NE BRISE. Objekat koji je nestao iz vCentera ostaje u bazi
// sa nepomerenim last_seen; zakljucak "obrisan je" donosi kasnija obrada, uz
// proveru da li je run uopste bio potpun.
//
// O poredjenju: da li se nesto promenilo odlucuje ISKLJUCIVO payload_hash,
// koji se racuna na strani DTO-a. Poredjenje polja ispod sluzi samo da se
// utvrdi KOJA su se polja promenila, za zapis u journal. Zato eventualna
// nepreciznost u pretvaranju tipova iz baze moze da uprlja prikaz razlike,
// ali ne moze da izmisli promenu koje nema.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;
use SyncOps\Source\Dto\InventoryDto;

abstract class InventoryRepository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    /** Naziv tabele sa trenutnim stanjem, npr. vc_vm. */
    abstract protected function table(): string;

    /** Naziv tabele sa journalom promena, npr. vc_vm_change. */
    abstract protected function changeTable(): string;

    /** Kolona u journalu koja pokazuje na objekat, npr. vm_id. */
    abstract protected function changeForeignKey(): string;

    /**
     * Upisi prikupljene objekte.
     *
     * @param list<InventoryDto> $objects
     * @return array{found: int, created: int, changed: int, unchanged: int}
     */
    public function persist(int $integrationId, int $runId, array $objects): array
    {
        $stats = ['found' => count($objects), 'created' => 0, 'changed' => 0, 'unchanged' => 0];

        if ($objects === []) {
            return $stats;
        }

        $existing = $this->existing($integrationId);

        foreach ($objects as $object) {
            $moref = $object->moref();
            $row   = $existing[$moref] ?? null;

            if ($row === null) {
                $this->insert($integrationId, $runId, $object);
                $stats['created']++;

                continue;
            }

            if (hash_equals((string) $row['payload_hash'], $object->hash())) {
                $this->touch((int) $row['id'], $runId);
                $stats['unchanged']++;

                continue;
            }

            $this->update((int) $row['id'], $runId, $object);
            $this->journal((int) $row['id'], $runId, $row, $object->comparable());
            $stats['changed']++;
        }

        return $stats;
    }

    // -----------------------------------------------------------------

    /**
     * Sve postojece objekte ucitava JEDNIM upitom, umesto po jedan SELECT za
     * svaku od 153 masine.
     *
     * @return array<string, array<string, mixed>> moref => red
     */
    protected function existing(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE integration_id = :id'
        );
        $stmt->execute(['id' => $integrationId]);

        $byMoref = [];

        foreach ($stmt->fetchAll() as $row) {
            $byMoref[(string) $row['moref']] = $row;
        }

        return $byMoref;
    }

    protected function insert(int $integrationId, int $runId, InventoryDto $object): void
    {
        $values = $object->comparable();

        $columns = array_merge(
            ['integration_id', 'moref', 'payload_hash', 'first_seen', 'last_seen', 'first_run_id', 'last_run_id'],
            array_keys($values)
        );

        $placeholders = array_merge(
            ['?', '?', '?', 'UTC_TIMESTAMP()', 'UTC_TIMESTAMP()', '?', '?'],
            array_fill(0, count($values), '?')
        );

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        ));

        $stmt->execute(array_merge(
            [$integrationId, $object->moref(), $object->hash(), $runId, $runId],
            array_map([$this, 'bindable'], array_values($values))
        ));
    }

    /** Nista se nije promenilo — samo potvrdi da je objekat jos vidjen. */
    protected function touch(int $id, int $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->table() . '
                SET last_seen = UTC_TIMESTAMP(), last_run_id = :run
              WHERE id = :id'
        );
        $stmt->execute(['run' => $runId, 'id' => $id]);
    }

    protected function update(int $id, int $runId, InventoryDto $object): void
    {
        $values = $object->comparable();

        $assignments = array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($values)
        );

        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE %s SET %s, payload_hash = ?, last_seen = UTC_TIMESTAMP(), last_run_id = ? WHERE id = ?',
            $this->table(),
            implode(', ', $assignments)
        ));

        $stmt->execute(array_merge(
            array_map([$this, 'bindable'], array_values($values)),
            [$object->hash(), $runId, $id]
        ));
    }

    /**
     * Zapis u journal: samo polja koja su se stvarno promenila.
     *
     * @param array<string, mixed> $oldRow Red iz baze, pre izmene
     * @param array<string, scalar|null> $new
     */
    protected function journal(int $id, int $runId, array $oldRow, array $new): void
    {
        $oldValues = [];
        $newValues = [];

        foreach ($new as $column => $newValue) {
            $old = $this->canonical($oldRow[$column] ?? null);

            if ($old === $this->canonical($newValue)) {
                continue;
            }

            // Oba kroz isti filter: baza vraca TINYINT kao 1, DTO ima true.
            // Bez ovoga bi ista vrednost u journalu stajala u dva tipa.
            $oldValues[$column] = $this->journalValue($oldRow[$column] ?? null);
            $newValues[$column] = $this->journalValue($newValue);
        }

        if ($newValues === []) {
            // Hash se razlikuje a nijedno polje ne izgleda promenjeno: to znaci
            // da pretvaranje tipova nije uspelo. Journal se ipak upisuje, da
            // promena ne nestane bez traga.
            $oldValues = ['_napomena' => 'razlika nije mogla da se razlozi po poljima'];
            $newValues = ['_hash' => 'promenjen'];
        }

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s, run_id, changed_at, old_values, new_values)
             VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)',
            $this->changeTable(),
            $this->changeForeignKey()
        ));

        $stmt->execute([
            $id,
            $runId,
            json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * Vrednost za zapis u journal, svedena na jedan tip po koloni.
     * Logicke vrednosti idu kao 0/1, isto kako stoje u bazi.
     */
    protected function journalValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && ($value === '0' || $value === '1')) {
            return (int) $value;
        }

        return $value;
    }

    /** PDO ne zna da veze bool; TINYINT(1) ocekuje 0 ili 1. */
    protected function bindable(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? 1 : 0) : $value;
    }

    /**
     * Svodi vrednost iz baze i vrednost iz DTO-a na isti oblik, da poredjenje
     * ne prijavi laznu razliku samo zato sto MySQL vraca "1" tamo gde PHP ima
     * true, ili int tamo gde PHP ima string.
     */
    protected function canonical(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
