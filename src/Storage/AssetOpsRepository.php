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
        $now    = $this->now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_reference
                (integration_id, kind, slug, name, parent_slug, depth, position,
                 color, payload, first_seen, last_seen)
             VALUES
                (:integration, :kind, :slug, :name, :parent, :depth, :position,
                 :color, :payload, :now, :now)
             ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                parent_slug = VALUES(parent_slug),
                depth       = VALUES(depth),
                position    = VALUES(position),
                color       = VALUES(color),
                payload     = VALUES(payload),
                last_seen   = VALUES(last_seen)'
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
                'now'         => $now,
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

        // Atribut obrisan u AssetOPS-u ne nestaje iz nase tabele — redovi se
        // ne brisu. Prepoznaje se po tome sto mu last_seen zaostaje za
        // ostalima iste vrste, isto kao i svuda drugde. Prikaz ga onda moze
        // oznaciti umesto da ga tiho izostavi, a sloj upisa da ga zaobidje:
        // upis u atribut kog vise nema ne bi prosao, a ako je bas on kljuc
        // spajanja, posledica je gora od greske pri upisu.
        $fresh = $this->lastSeenByKind($integrationId)['eav_attribute'] ?? null;

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

            $row['missing'] = $fresh !== null && (string) $row['last_seen'] < $fresh;

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

            $byKind[$kind] = ['type_slug' => $row['type_slug']];
        }

        return $byKind;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMapping(): array
    {
        return ['type_slug' => null];
    }

    /**
     * Redovi koji nisu bili u poslednjem osvezavanju svoje vrste.
     *
     * To su slugovi obrisani u AssetOPS-u. Ne brisu se sami: dok mapiranje
     * jos pokazuje na njih, treba da se vide — inace bi podesavanje tiho
     * pokazivalo u prazno.
     *
     * Uz svaki red stoji i da li ga nesto jos koristi.
     *
     * @return list<array<string, mixed>>
     */
    public function staleReference(int $integrationId): array
    {
        $fresh = $this->lastSeenByKind($integrationId);

        if ($fresh === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ao_reference WHERE integration_id = :i ORDER BY kind, position, name'
        );
        $stmt->execute(['i' => $integrationId]);

        $used = $this->referencedSlugs($integrationId);
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $max = $fresh[(string) $row['kind']] ?? null;

            if ($max === null || (string) $row['last_seen'] >= $max) {
                continue;
            }

            $row['in_use'] = isset($used[(string) $row['slug']]);

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Obrisi nestale redove koje nista ne koristi.
     *
     * Red na koji jos pokazuje mapiranje se ZADRZAVA. Brisanje bi uklonilo
     * jedino mesto na kom se vidi da podesavanje pokazuje u nesto cega vise
     * nema, a greska bi se otkrila tek pri upisu.
     *
     * @return array{removed: int, kept: int}
     */
    public function removeStaleReference(int $integrationId): array
    {
        $stale   = $this->staleReference($integrationId);
        $removed = 0;
        $kept    = 0;

        $delete = $this->pdo->prepare('DELETE FROM ao_reference WHERE id = :id');

        foreach ($stale as $row) {
            if ($row['in_use'] === true) {
                $kept++;

                continue;
            }

            $delete->execute(['id' => $row['id']]);
            $removed++;
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Svi slugovi na koje jos nesto pokazuje, kao skup.
     *
     * Namerno je plitko: ne gleda se odakle slug dolazi nego samo da li se
     * negde pominje. Zastita viska je bezopasna, a propusteno brisanje
     * referentnog reda nije.
     *
     * @return array<string, true>
     */
    private function referencedSlugs(int $integrationId): array
    {
        $used = [];

        $types = $this->pdo->prepare('SELECT type_slug FROM ao_mapping WHERE integration_id = :i');
        $types->execute(['i' => $integrationId]);

        foreach ($types->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            if ($slug !== null && $slug !== '') {
                $used[(string) $slug] = true;
            }
        }

        $fields = $this->pdo->prepare(
            'SELECT target_kind, target_key, constant_value FROM ao_field_map WHERE integration_id = :i'
        );
        $fields->execute(['i' => $integrationId]);

        foreach ($fields->fetchAll() as $row) {
            if ((string) $row['target_kind'] === 'eav') {
                $used[(string) $row['target_key']] = true;
            }

            $constant = (string) ($row['constant_value'] ?? '');

            if ($constant === '') {
                continue;
            }

            // Visestruka veza je zapamcena kao JSON niz slugova.
            $decoded = json_decode($constant, true);

            foreach (is_array($decoded) ? $decoded : [$constant] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $used[$slug] = true;
                }
            }
        }

        return $used;
    }

    // -----------------------------------------------------------------
    // Zateceni CI-jevi
    // -----------------------------------------------------------------

    /**
     * Upisi procitane CI-jeve jednog entiteta. Vraca koliko je novo.
     *
     * Kao i referentni spiskovi: first_seen se postavlja samo pri prvom
     * vidjenju, redovi se ne brisu. CI koji nestane iz AssetOPS-a ostaje sa
     * starim last_seen, pa prikaz moze da ga oznaci — a faza nestanka da
     * predlozi promenu statusa umesto da zapis nestane i iz nase baze.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{new: int, seen: int}
     */
    public function storeCi(int $integrationId, string $entity, array $rows): array
    {
        if ($rows === []) {
            return ['new' => 0, 'seen' => 0];
        }

        $before = $this->ciUuids($integrationId, $entity);
        $now    = $this->now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_ci
                (integration_id, entity, uuid, name, description, type_slug, status_slug,
                 location_slug, serial_number, correlation_key, correlation_value,
                 payload, remote_updated_at, first_seen, last_seen)
             VALUES
                (:integration, :entity, :uuid, :name, :description, :type, :status,
                 :location, :serial, :corr_key, :corr_value,
                 :payload, :remote, :now, :now)
             ON DUPLICATE KEY UPDATE
                entity            = VALUES(entity),
                name              = VALUES(name),
                description       = VALUES(description),
                type_slug         = VALUES(type_slug),
                status_slug       = VALUES(status_slug),
                location_slug     = VALUES(location_slug),
                serial_number     = VALUES(serial_number),
                correlation_key   = VALUES(correlation_key),
                correlation_value = VALUES(correlation_value),
                payload           = VALUES(payload),
                remote_updated_at = VALUES(remote_updated_at),
                last_seen         = VALUES(last_seen)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'integration' => $integrationId,
                'entity'      => $entity,
                'uuid'        => $row['uuid'],
                'name'        => $row['name'],
                'description' => $row['description'] ?? null,
                'type'        => $row['type_slug'],
                'status'      => $row['status_slug'],
                'location'    => $row['location_slug'],
                'serial'      => $row['serial_number'],
                'corr_key'    => $row['correlation_key'],
                'corr_value'  => $row['correlation_value'],
                'payload'     => $row['payload'],
                'remote'      => $row['remote_updated_at'],
                'now'         => $now,
            ]);
        }

        $new = 0;

        foreach ($rows as $row) {
            if (!in_array($row['uuid'], $before, true)) {
                $new++;
            }
        }

        return ['new' => $new, 'seen' => count($rows)];
    }

    /**
     * Jedno vreme za celu seriju upisa.
     *
     * Ranije je u SQL-u stajalo UTC_TIMESTAMP(), a MySQL tu funkciju racuna
     * jednom PO NAREDBI, ne po transakciji. Posto se svaki red upisuje svojim
     * execute()-om, serija od dvesta redova dobijala je vremena razvucena
     * preko sekunde ili dve. Prikaz zatim uzima MAX(last_seen) kao „poslednje
     * osvezavanje" i oboji sve ispod toga — pa su redovi upisani sekund ranije
     * u istom prolazu izgledali kao da nisu vidjeni. Nasumicno, jer zavisi od
     * toga da li je upis presao granicu sekunde.
     *
     * Cita se iz baze, a ne gmdate() iz PHP-a, da sat ostane jedan isti:
     * poredjenje ide nad vrednostima koje je upisala baza, pa bi razlika
     * izmedju kontejnera vratila isti kvar na druga vrata.
     */
    private function now(): string
    {
        return (string) $this->pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function ciUuids(int $integrationId, string $entity): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uuid FROM ao_ci WHERE integration_id = :i AND entity = :e'
        );
        $stmt->execute(['i' => $integrationId, 'e' => $entity]);

        /** @var list<string> $uuids */
        $uuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $uuids;
    }

    /**
     * Svi CI-jevi jednog entiteta, za prikaz.
     *
     * @return list<array<string, mixed>>
     */
    public function ciByEntity(int $integrationId, string $entity): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ao_ci
              WHERE integration_id = :i AND entity = :e
              ORDER BY name'
        );
        $stmt->execute(['i' => $integrationId, 'e' => $entity]);

        return $stmt->fetchAll();
    }

    /**
     * Brojevi po entitetu: ukupno, sa vrednoscu kljuca, i poslednje vidjenje.
     *
     * Entitet koji nije prikupljen ne pojavljuje se u rezultatu — pozivalac
     * razlikuje „nije prikupljeno" od „prikupljeno, nema ni jednog".
     *
     * @return array<string, array{total: int, with_key: int, last_seen: ?string}>
     */
    public function ciStats(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT entity,
                    COUNT(*) AS total,
                    SUM(correlation_value IS NOT NULL) AS with_key,
                    MAX(last_seen) AS last_seen
               FROM ao_ci
              WHERE integration_id = :i
              GROUP BY entity'
        );
        $stmt->execute(['i' => $integrationId]);

        $byEntity = [];

        foreach ($stmt->fetchAll() as $row) {
            $byEntity[(string) $row['entity']] = [
                'total'     => (int) $row['total'],
                'with_key'  => (int) $row['with_key'],
                'last_seen' => $row['last_seen'] === null ? null : (string) $row['last_seen'],
            ];
        }

        return $byEntity;
    }

    /**
     * Vrednosti kljuca spajanja koje nosi vise od jednog CI-ja.
     *
     * Duplikat se ne sprecava pri upisu nego se prijavljuje: dva CI-ja sa
     * istim instance_uuid-om znace da je negde nastao dvojnik, i to je nalaz
     * za coveka, ne greska baze.
     *
     * @return list<array{correlation_value: string, count: int, names: string}>
     */
    public function ciDuplicateKeys(int $integrationId, string $entity): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT correlation_value, COUNT(*) AS cnt, GROUP_CONCAT(name ORDER BY name SEPARATOR ", ") AS names
               FROM ao_ci
              WHERE integration_id = :i AND entity = :e AND correlation_value IS NOT NULL
              GROUP BY correlation_value
             HAVING cnt > 1
              ORDER BY cnt DESC, correlation_value'
        );
        $stmt->execute(['i' => $integrationId, 'e' => $entity]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'correlation_value' => (string) $row['correlation_value'],
                'count'             => (int) $row['cnt'],
                'names'             => (string) $row['names'],
            ];
        }

        return $rows;
    }

    /**
     * Polje koje za svaku vrstu izvora nosi kljuc spajanja, kao "eav:<slug>"
     * ili "native:<polje>".
     *
     * Najvise jedan po vrsti izvora — pravilo cuva saveFieldMap. Vrsta bez
     * kljuca dobija null.
     *
     * @return array<string, ?string> vrsta izvora => ref ili null
     */
    public function correlationRefs(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_kind, target_kind, target_key
               FROM ao_field_map
              WHERE integration_id = :i AND is_correlation = 1'
        );
        $stmt->execute(['i' => $integrationId]);

        $byKind = ['vm' => null, 'host' => null];

        foreach ($stmt->fetchAll() as $row) {
            $byKind[(string) $row['source_kind']]
                = $row['target_kind'] . ':' . $row['target_key'];
        }

        return $byKind;
    }

    /**
     * Tip CI-ja koji se pravi od ove vrste izvora.
     *
     * Jedino sto je ostalo u ao_mapping. Lokacija i visibility grupe su u
     * migraciji 0014, a broj rek jedinica u 0015, presli u ao_field_map —
     * sve troje su polja kao i ostala. Tip nije: on odredjuje koje je VRSTE
     * CI koji se pravi, pa se bira pre tabele, a ne u njoj.
     */
    public function saveMapping(
        int $integrationId,
        string $sourceKind,
        ?string $typeSlug,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_mapping
                (integration_id, source_kind, type_slug, updated_at)
             VALUES
                (:integration, :kind, :type, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                type_slug  = VALUES(type_slug),
                updated_at = UTC_TIMESTAMP()'
        );

        $stmt->execute([
            'integration' => $integrationId,
            'kind'        => $sourceKind,
            'type'        => ($typeSlug === null || $typeSlug === '') ? null : $typeSlug,
        ]);
    }
}
