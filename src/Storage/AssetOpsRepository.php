<?php
// src/Storage/AssetOpsRepository.php
//
// Upis i citanje AssetOPS referentnih podataka i mapiranja.
//
// Isto pravilo kao u inventaru: redovi se NE BRISU. Slug koji nestane iz
// AssetOPS-a ostaje u tabeli sa starim last_seen, pa prikaz moze da ga
// oznaci. Brisanje bi tiho uklonilo lokaciju na koju mapiranje jos pokazuje,
// i greska bi se otkrila tek pri upisu.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;

final class AssetOpsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // -----------------------------------------------------------------
    // Referentni podaci
    // -----------------------------------------------------------------

    /**
     * Upisi jedan spisak. Vraca koliko je redova novo, a koliko osvezeno.
     *
     * first_seen se postavlja samo pri prvom vidjenju; kasnija osvezavanja ga
     * ne diraju, pa se vidi otkad slug postoji.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{new: int, seen: int}
     */
    public function storeKind(int $integrationId, string $kind, array $rows): array
    {
        if ($rows === []) {
            return ['new' => 0, 'seen' => 0];
        }

        $before = $this->slugs($integrationId, $kind);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_reference
                (integration_id, kind, slug, name, parent_slug, depth, position,
                 color, payload, first_seen, last_seen)
             VALUES
                (:integration, :kind, :slug, :name, :parent, :depth, :position,
                 :color, :payload, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                parent_slug = VALUES(parent_slug),
                depth       = VALUES(depth),
                position    = VALUES(position),
                color       = VALUES(color),
                payload     = VALUES(payload),
                last_seen   = UTC_TIMESTAMP()'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'integration' => $integrationId,
                'kind'        => $kind,
                'slug'        => $row['slug'],
                'name'        => $row['name'],
                'parent'      => $row['parent_slug'],
                'depth'       => $row['depth'],
                'position'    => $row['position'],
                'color'       => $row['color'],
                'payload'     => $row['payload'],
            ]);
        }

        $new = 0;

        foreach ($rows as $row) {
            if (!in_array($row['slug'], $before, true)) {
                $new++;
            }
        }

        return ['new' => $new, 'seen' => count($rows)];
    }

    /**
     * @return list<string>
     */
    private function slugs(int $integrationId, string $kind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug FROM ao_reference WHERE integration_id = :i AND kind = :k'
        );
        $stmt->execute(['i' => $integrationId, 'k' => $kind]);

        /** @var list<string> $slugs */
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $slugs;
    }

    /**
     * Jedan spisak za prikaz, u redosledu obilaska stabla.
     *
     * @return list<array<string, mixed>>
     */
    public function byKind(int $integrationId, string $kind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ao_reference
              WHERE integration_id = :i AND kind = :k
              ORDER BY position, name'
        );
        $stmt->execute(['i' => $integrationId, 'k' => $kind]);

        return $stmt->fetchAll();
    }

    /**
     * Najskorije vidjenje po vrsti. Red stariji od toga nije bio u poslednjem
     * osvezavanju — isti nacin oznacavanja kao u inventaru, samo bez run-a.
     *
     * @return array<string, string> kind => last_seen
     */
    public function lastSeenByKind(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT kind, MAX(last_seen) AS last_seen
               FROM ao_reference
              WHERE integration_id = :i
              GROUP BY kind'
        );
        $stmt->execute(['i' => $integrationId]);

        $byKind = [];

        foreach ($stmt->fetchAll() as $row) {
            $byKind[(string) $row['kind']] = (string) $row['last_seen'];
        }

        return $byKind;
    }

    /**
     * Slug => ime, za padajuce spiskove u mapiranju.
     *
     * Kod hijerarhijskih vrsta ime se uvlaci po dubini, da se u spisku vidi
     * gde lokacija stoji u stablu.
     *
     * @return array<string, string>
     */
    public function options(int $integrationId, string $kind): array
    {
        $options = [];

        foreach ($this->byKind($integrationId, $kind) as $row) {
            $indent = str_repeat('— ', (int) $row['depth']);

            $options[(string) $row['slug']] = $indent . (string) $row['name'];
        }

        return $options;
    }

    /**
     * EAV atributi primenjivi na jedan entitet (virtual-asset ili asset).
     *
     * Atribut je vezan za ENTITET, ne za tip CI-ja, pa se spisak ne menja
     * kada se promeni izabrani tip.
     *
     * attribute_entities ume da stigne kao niz stringova ili kao niz objekata
     * sa slugom — prihvataju se oba, da jedna izmena na njihovoj strani ne
     * isprazni ceo ekran.
     *
     * @return list<array<string, mixed>>
     */
    public function attributesForEntity(int $integrationId, string $entity): array
    {
        $matching = [];

        foreach ($this->byKind($integrationId, 'eav_attribute') as $row) {
            $payload = json_decode((string) $row['payload'], true);

            if (!is_array($payload)) {
                continue;
            }

            if (!in_array($entity, $this->entitySlugs($payload['attribute_entities'] ?? []), true)) {
                continue;
            }

            $type = $payload['attribute_type'] ?? null;

            $row['attribute_type'] = is_array($type)
                ? (string) ($type['slug'] ?? $type['name'] ?? '')
                : (string) ($type ?? '');

            $row['possible_values'] = is_array($payload['possible_select_values'] ?? null)
                ? $payload['possible_select_values']
                : [];

            $matching[] = $row;
        }

        return $matching;
    }

    /**
     * @return list<string>
     */
    private function entitySlugs(mixed $entities): array
    {
        if (!is_array($entities)) {
            return [];
        }

        $slugs = [];

        foreach ($entities as $entity) {
            if (is_string($entity)) {
                $slugs[] = $entity;
            } elseif (is_array($entity)) {
                $slug = $entity['slug'] ?? $entity['name'] ?? null;

                if (is_string($slug)) {
                    $slugs[] = $slug;
                }
            }
        }

        return $slugs;
    }

    // -----------------------------------------------------------------
    // Mapiranje polja
    // -----------------------------------------------------------------

    /**
     * Mapiranje polja za jednu vrstu izvora, po cilju.
     *
     * Kljuc je "vrsta:ime" ("native:name", "eav:instance-uuid"), isto kako
     * forma imenuje svoja polja — da prikaz ne mora nista da sastavlja.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldMap(int $integrationId, string $sourceKind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ao_field_map
              WHERE integration_id = :i AND source_kind = :k'
        );
        $stmt->execute(['i' => $integrationId, 'k' => $sourceKind]);

        $byTarget = [];

        foreach ($stmt->fetchAll() as $row) {
            $byTarget[$row['target_kind'] . ':' . $row['target_key']] = [
                'source_field'   => $row['source_field'],
                'constant_value' => $row['constant_value'],
                'write_policy'   => (string) $row['write_policy'],
                'is_correlation' => (bool) $row['is_correlation'],
            ];
        }

        return $byTarget;
    }

    /**
     * Prepisi mapiranje za jednu vrstu izvora.
     *
     * Brise pa upisuje, u transakciji: forma uvek salje ceo spisak, pa bi
     * dopunjavanje ostavljalo redove za ciljeve koji su u medjuvremenu
     * nestali iz AssetOPS-a.
     *
     * Kljuc spajanja je najvise jedan po vrsti izvora. Pravilo se cuva ovde
     * jer ga jedinstveni indeks ne moze iskazati, a dva kljuca bi znacila dva
     * razlicita odgovora na pitanje koji CI odgovara kojoj masini.
     *
     * @param list<array{target_kind: string, target_key: string, source_field: ?string,
     *                   constant_value: ?string, write_policy: string, is_correlation: bool}> $rows
     */
    public function saveFieldMap(int $integrationId, string $sourceKind, array $rows): void
    {
        $this->pdo->beginTransaction();

        try {
            $clear = $this->pdo->prepare(
                'DELETE FROM ao_field_map WHERE integration_id = :i AND source_kind = :k'
            );
            $clear->execute(['i' => $integrationId, 'k' => $sourceKind]);

            $insert = $this->pdo->prepare(
                'INSERT INTO ao_field_map
                    (integration_id, source_kind, target_kind, target_key,
                     source_field, constant_value, write_policy, is_correlation, updated_at)
                 VALUES
                    (:i, :k, :tk, :key, :src, :const, :policy, :corr, UTC_TIMESTAMP())'
            );

            $correlationUsed = false;

            foreach ($rows as $row) {
                // Red koji nista ne radi i nije kljuc nema sta da se pamti.
                if ($row['write_policy'] === 'never' && $row['is_correlation'] === false) {
                    continue;
                }

                $isCorrelation = $row['is_correlation'] && !$correlationUsed;

                if ($isCorrelation) {
                    $correlationUsed = true;
                }

                $insert->execute([
                    'i'      => $integrationId,
                    'k'      => $sourceKind,
                    'tk'     => $row['target_kind'],
                    'key'    => $row['target_key'],
                    'src'    => $row['source_field'] === '' ? null : $row['source_field'],
                    'const'  => $row['constant_value'] === '' ? null : $row['constant_value'],
                    // Kljuc spajanja se po definiciji upisuje samo pri
                    // kreiranju: instance_uuid se ne menja, a prepisivanje bi
                    // znacilo da vise nije kljuc.
                    'policy' => $isCorrelation ? 'create_only' : $row['write_policy'],
                    'corr'   => $isCorrelation ? 1 : 0,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // Mapiranje
    // -----------------------------------------------------------------

    /**
     * Oba reda mapiranja, po vrsti izvora. Vrsta koja jos nije podesena
     * dobija prazan red, da prikaz ne mora da proverava postojanje.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapping(int $integrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ao_mapping WHERE integration_id = :i');
        $stmt->execute(['i' => $integrationId]);

        $byKind = [
            'vm'   => $this->emptyMapping(),
            'host' => $this->emptyMapping(),
        ];

        foreach ($stmt->fetchAll() as $row) {
            $kind = (string) $row['source_kind'];

            $byKind[$kind] = [
                'type_slug'         => $row['type_slug'],
                'location_slug'     => $row['location_slug'],
                'visibility_groups' => $this->decodeGroups($row['visibility_groups']),
                'units_required'    => $row['units_required'],
            ];
        }

        return $byKind;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMapping(): array
    {
        return [
            'type_slug'         => null,
            'location_slug'     => null,
            'visibility_groups' => [],
            'units_required'    => null,
        ];
    }

    /**
     * @param list<string> $visibilityGroups
     */
    public function saveMapping(
        int $integrationId,
        string $sourceKind,
        ?string $typeSlug,
        ?string $locationSlug,
        array $visibilityGroups,
        ?int $unitsRequired,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_mapping
                (integration_id, source_kind, type_slug, location_slug,
                 visibility_groups, units_required, updated_at)
             VALUES
                (:integration, :kind, :type, :location, :groups, :units, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                type_slug         = VALUES(type_slug),
                location_slug     = VALUES(location_slug),
                visibility_groups = VALUES(visibility_groups),
                units_required    = VALUES(units_required),
                updated_at        = UTC_TIMESTAMP()'
        );

        $stmt->execute([
            'integration' => $integrationId,
            'kind'        => $sourceKind,
            'type'        => $typeSlug === '' ? null : $typeSlug,
            'location'    => $locationSlug === '' ? null : $locationSlug,
            'groups'      => $visibilityGroups === [] ? null : json_encode(array_values($visibilityGroups)),
            'units'       => $unitsRequired,
        ]);
    }

    /**
     * @return list<string>
     */
    private function decodeGroups(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }
}
