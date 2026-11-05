<?php
// src/Source/AssetOps/WriteProbe.php
//
// Meri kako se AssetOPS ponasa pri upisu.
//
// Postoji zato sto tri stvari koje odredjuju ceo sloj sinhronizacije nisu
// potvrdjene merenjem, nego su do sada bile citanje swagger-a:
//
//   1. Da li je PUT pun ili parcijalan. Ako brise izostavljena polja, onda
//      izmena jednog polja trazi slanje CELOG zapisa — i odgovornost za sva
//      polja koja se ne zele dirati. PATCH na samom zapisu ne postoji.
//
//   2. Da li se attribute_values stvarno upisuje. Server ne odbija telo sa
//      atributima, ali odgovor na POST ne prikazuje atribute, pa iz njega
//      nema sta da se zakljuci. Ako se ne upisuje, kljuc spajanja ne radi —
//      a na njemu stoji sve ostalo.
//
//   3. Da li server menja primljenu vrednost. Ako skrati ili preoblikuje,
//      sledece citanje vidi razliku i program upisuje opet, u nedogled.
//
// Merenje je destruktivno samo po sebi: pravi privremeni CI i brise ga.
// Brisanje ide kroz finally, pa zapis ne ostaje ni kada merenje pukne.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use Throwable;

final class WriteProbe
{
    /** Ime privremenog zapisa; prefiks da se prepozna ako ipak ostane. */
    private const NAME_PREFIX = 'SyncOPS-proba-';

    /**
     * Vrednost sa viskovima na krajevima i dvostrukim razmakom unutra.
     * Ako je server vrati nepromenjenu, ne normalizuje.
     */
    private const MESSY_VALUE = '  ab  CD-12  ';

    public function __construct(private readonly AssetOpsClient $client)
    {
    }

    /**
     * @return array{
     *     ok: bool,
     *     findings: list<array<string, string>>,
     *     notes: list<string>,
     *     calls: int,
     *     created: ?string,
     *     cleaned: bool
     * }
     */
    public function run(): array
    {
        $findings = [];
        $notes    = [];
        $created  = null;
        $cleaned  = false;

        try {
            $ready = $this->prerequisites($notes);

            if ($ready === null) {
                return $this->result($findings, $notes, null, false, ok: false);
            }

            [$location, $group, $attribute] = $ready;

            $name = self::NAME_PREFIX . gmdate('Ymd-His');

            // -------------------------------------------------------------
            // 1. Kreiranje
            // -------------------------------------------------------------
            $body = [
                'name'              => $name,
                'location'          => $location,
                'visibility_groups' => [$group],
                'description'       => 'Privremeni zapis koji je napravio SyncOPS radi merenja ponasanja upisa.',
                'serial_number'     => self::MESSY_VALUE,
            ];

            if ($attribute !== null) {
                // Oblik iz seme CreateVirtualAsset: objekat {slug: vrednost}.
                $body['attribute_values'] = [$attribute => self::MESSY_VALUE];
            }

            $response = $this->client->raw('/api/virtual-assets/', 'POST', $body);
            $data     = is_array($response['data'] ?? null) ? $response['data'] : [];
            $created  = isset($data['uuid']) ? (string) $data['uuid'] : null;

            $findings[] = $this->finding(
                'post_status',
                'Kreiranje vraća',
                'HTTP ' . (int) ($response['status'] ?? 0) . ', message: ' . (string) ($response['message'] ?? '—'),
                (int) ($response['status'] ?? 0) === 201 ? 'pazi' : 'ok'
            );

            $findings[] = $this->finding(
                'post_echo',
                'Odgovor na kreiranje vraća poslata polja',
                'vraćeno: ' . (implode(', ', array_keys($data)) ?: '—'),
                array_key_exists('serial_number', $data) ? 'ok' : 'pazi'
            );

            if ($created === null) {
                $notes[] = 'Odgovor na kreiranje ne sadrzi uuid, pa dalja merenja nisu moguca.';

                return $this->result($findings, $notes, null, false, ok: false);
            }

            $path  = '/api/virtual-assets/' . rawurlencode($created) . '/';
            $after = $this->readRecord($path);

            // -------------------------------------------------------------
            // 2. Da li se atribut upisao
            // -------------------------------------------------------------
            if ($attribute === null) {
                $findings[] = $this->finding(
                    'attribute_write',
                    'Upis EAV atributa',
                    'nije mereno — nijedan atribut nije vezan za entitet virtual-asset',
                    'nepoznato'
                );
            } else {
                $value = $this->attributeValue($after, $attribute);

                $findings[] = $this->finding(
                    'attribute_write',
                    'EAV atribut se upisuje kao {slug: vrednost}',
                    $value === null
                        ? "atribut „{$attribute}” posle upisa nema vrednost"
                        : "pročitano nazad: " . $this->show($value),
                    $value === null ? 'lose' : 'ok'
                );
            }

            // -------------------------------------------------------------
            // 3. Normalizacija
            // -------------------------------------------------------------
            $serial = $after['serial_number'] ?? null;

            $findings[] = $this->finding(
                'normalization',
                'Server vraća vrednost nepromenjenu',
                'poslato ' . $this->show(self::MESSY_VALUE) . ', vraćeno ' . $this->show($serial),
                $serial === self::MESSY_VALUE ? 'ok' : 'pazi'
            );

            // -------------------------------------------------------------
            // 4. Pun ili parcijalan PUT
            // -------------------------------------------------------------
            // Salje se samo ime, uz lokaciju koju UpdateVirtualAsset trazi
            // kao obaveznu. Ako posle toga opis i serijski broj nestanu, PUT
            // je pun i brise izostavljeno.
            $this->client->raw($path, 'PUT', [
                'name'     => $name . '-izmenjeno',
                'location' => $location,
            ]);

            $afterPut = $this->readRecord($path);

            $kept = [];
            $lost = [];

            foreach (['description', 'serial_number'] as $field) {
                $before = $after[$field] ?? null;
                $now    = $afterPut[$field] ?? null;

                if ($before === null || $before === '') {
                    continue;
                }

                if ($now === $before) {
                    $kept[] = $field;
                } else {
                    $lost[] = $field;
                }
            }

            if ($attribute !== null) {
                $before = $this->attributeValue($after, $attribute);
                $now    = $this->attributeValue($afterPut, $attribute);

                if ($before !== null) {
                    if ($now === $before) {
                        $kept[] = 'attribute_values.' . $attribute;
                    } else {
                        $lost[] = 'attribute_values.' . $attribute;
                    }
                }
            }

            $findings[] = $this->finding(
                'put_mode',
                'PUT je parcijalan (izostavljena polja preživljavaju)',
                $lost === []
                    ? 'sačuvano: ' . (implode(', ', $kept) ?: '—')
                    : 'izgubljeno: ' . implode(', ', $lost)
                      . ($kept === [] ? '' : '; sačuvano: ' . implode(', ', $kept)),
                $lost === [] ? 'ok' : 'lose'
            );

            if ($lost !== []) {
                $notes[] = 'PUT je pun: izmena jednog polja mora da posalje ceo zapis, '
                         . 'pa sinhronizacija preuzima odgovornost i za polja koja ne zeli da dira.';
            }

            $findings[] = $this->finding(
                'put_name',
                'PUT menja polje koje je poslato',
                'ime posle izmene: ' . $this->show($afterPut['name'] ?? null),
                ($afterPut['name'] ?? null) === $name . '-izmenjeno' ? 'ok' : 'lose'
            );
        } catch (Throwable $e) {
            $notes[] = 'Merenje prekinuto: ' . $e->getMessage();
        } finally {
            if ($created !== null) {
                $cleaned = $this->cleanUp($created, $findings, $notes);
            }
        }

        $ok = array_filter($findings, static fn (array $f): bool => $f['outcome'] === 'lose') === [];

        return $this->result($findings, $notes, $created, $cleaned, $ok);
    }

    // -----------------------------------------------------------------

    /**
     * Lokacija, visibility grupa i EAV atribut bez kojih merenje ne moze.
     *
     * @param list<string> $notes
     * @return array{0: string, 1: string, 2: ?string}|null
     */
    private function prerequisites(array &$notes): ?array
    {
        $location = $this->firstSlug('/api/locations/');
        $group    = $this->firstSlug('/api/visibility-groups/');

        if ($location === null) {
            $notes[] = 'Nema nijedne lokacije — CreateVirtualAsset je trazi kao obaveznu.';
        }

        if ($group === null) {
            $notes[] = 'Nema nijedne visibility grupe — CreateVirtualAsset je trazi kao obaveznu.';
        }

        if ($location === null || $group === null) {
            return null;
        }

        return [$location, $group, $this->firstAttributeFor('virtual-asset')];
    }

    private function firstSlug(string $path): ?string
    {
        $data  = $this->client->get($path, ['items-per-page' => 1]);
        $items = $data['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return null;
        }

        $first = reset($items);

        return is_array($first) && isset($first['slug']) ? (string) $first['slug'] : null;
    }

    /**
     * Prvi atribut vezan za zadati entitet. Tekstualni se bira pre ostalih:
     * char prima bilo sta, pa merenje ne pada na obliku vrednosti.
     */
    private function firstAttributeFor(string $entity): ?string
    {
        $fallback = null;

        foreach ($this->client->listAll('/api/eav/attributes/') as $attribute) {
            $slugs = [];

            foreach ((array) ($attribute['attribute_entities'] ?? []) as $bound) {
                $slugs[] = is_array($bound)
                    ? (string) ($bound['slug'] ?? $bound['name'] ?? '')
                    : (string) $bound;
            }

            if (!in_array($entity, $slugs, true)) {
                continue;
            }

            $slug = isset($attribute['slug']) ? (string) $attribute['slug'] : null;

            if ($slug === null) {
                continue;
            }

            $type = $attribute['attribute_type'] ?? null;
            $type = is_array($type) ? (string) ($type['slug'] ?? '') : (string) $type;

            if ($type === 'char') {
                return $slug;
            }

            $fallback ??= $slug;
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRecord(string $path): array
    {
        $data = $this->client->get($path);

        // Kod pojedinacnog zapisa "data" je sam zapis, ne omotac sa items.
        return $data;
    }

    /**
     * Vrednost jednog EAV atributa iz procitanog zapisa.
     *
     * attribute_values je NIZ redova, a slug je unutra pod "attribute" —
     * nije mapa {slug: vrednost}, iako se pri upisu salje bas tako.
     *
     * @param array<string, mixed> $record
     */
    private function attributeValue(array $record, string $slug): mixed
    {
        foreach ((array) ($record['attribute_values'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string) ($row['attribute']['slug'] ?? '') === $slug) {
                return $row['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, string>> $findings
     * @param list<string> $notes
     */
    private function cleanUp(string $uuid, array &$findings, array &$notes): bool
    {
        $path = '/api/virtual-assets/' . rawurlencode($uuid) . '/';

        try {
            $response = $this->client->raw($path, 'DELETE');
            $deleted  = ($response['success'] ?? false) === true;

            $findings[] = $this->finding(
                'delete',
                'Brisanje uspeva iz prvog poziva',
                'HTTP ' . (int) ($response['status'] ?? 0) . ', message: ' . (string) ($response['message'] ?? '—'),
                $deleted ? 'ok' : 'lose'
            );

            if (!$deleted) {
                $notes[] = "Privremeni zapis {$uuid} NIJE obrisan — obrisi ga rucno.";
            }

            return $deleted;
        } catch (Throwable $e) {
            $notes[] = "Privremeni zapis {$uuid} NIJE obrisan ({$e->getMessage()}) — obrisi ga rucno.";

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $key, string $label, string $detail, string $outcome): array
    {
        return ['key' => $key, 'label' => $label, 'detail' => $detail, 'outcome' => $outcome];
    }

    private function show(mixed $value): string
    {
        if ($value === null) {
            return '(nema)';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Navodnici da se vide razmaci na krajevima.
        return '„' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . '”';
    }

    /**
     * @param list<array<string, string>> $findings
     * @param list<string> $notes
     * @return array{ok: bool, findings: list<array<string,string>>, notes: list<string>,
     *                calls: int, created: ?string, cleaned: bool}
     */
    private function result(array $findings, array $notes, ?string $created, bool $cleaned, bool $ok): array
    {
        return [
            'ok'       => $ok,
            'findings' => $findings,
            'notes'    => $notes,
            'calls'    => $this->client->callCount(),
            'created'  => $created,
            'cleaned'  => $cleaned,
        ];
    }
}
