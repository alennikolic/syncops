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
