<?php
// src/Sync/Planner.php
//
// Sta bi se upisalo u AssetOPS, i zasto. Bez ijednog poziva i bez ijednog
// upisa — ulaz je inventar, procitani CI-jevi, veze i mapiranje, izlaz je
// spisak predlozenih akcija.
//
// TRI STANJA, NE DVA
//
//   vCenter sada     red iz vc_vm / vc_host
//   AssetOPS sada    red iz ao_ci
//   poslednje poslato  ono sto je SyncOPS upisao prosli put
//
// Trece ne odlucuje nego objasnjava: pravilo upisa vec kaze sme li se polje
// dirati. Sluzi da se razlikuje „vCenter se promenio" od „covek je ispravio
// u AssetOPS-u" — i da se to drugo vidi pre nego sto ga prepisemo.
//
// Poslednje poslato puni faza upisa; dok ga nema, poredjenje je dvostrano i
// rucna izmena se ne prepoznaje. To je pad u tacnost prikaza, ne u
// ispravnost odluke.
//
// TELO IZMENE SE SASTAVLJA OD PROCITANOG CI-JA, NE OD IZMENA
//
// Izmereno: PUT brise svaki EAV atribut koji nije poslat. Telo zato uvek
// nosi KOMPLETNU mapu attribute_values — zatecene vrednosti plus nase
// izmene. Da se sastavlja samo od onoga sto menjamo, prvi upis bi obrisao
// kljuc spajanja i veza sa tim CI-jem bi nestala.

declare(strict_types=1);

namespace SyncOps\Sync;

final class Planner
{
    /**
     * Predlog akcija za jednu vrstu izvora.
     *
     * @param list<array<string, mixed>>          $inventory
     * @param array<int, array<string, mixed>>    $links   veze po source_id
     * @param array<string, array<string, mixed>> $cis     CI-jevi po uuid
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, string>               $attributeTypes slug => tip
     *
     * @return array{
     *     actions: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public static function plan(
        array $inventory,
        array $links,
        array $cis,
        array $fieldMap,
        array $attributeTypes,
        string $sourceKind,
        ?string $typeSlug,
    ): array {
        $actions = [];
        $skipped = [];

        foreach ($inventory as $row) {
            $link = $links[(int) $row['id']] ?? null;
            $ci   = $link === null ? null : ($cis[(string) $link['ci_uuid']] ?? null);

            // Veza postoji, a CI-ja nema. Spajanje to vec prijavljuje; ovde
            // se objekat samo preskace, da se ne bi kreirao duplikat pored
            // CI-ja koji je mozda obrisan greskom.
            if ($link !== null && $ci === null) {
                $skipped[] = self::skip($row, 'veza pokazuje na CI kojeg nema — vidi Spajanje');

                continue;
            }

            $blocked = self::blocked($typeSlug, $fieldMap, $sourceKind, $ci === null);

            if ($blocked !== null) {
                $skipped[] = self::skip($row, $blocked);

                continue;
            }

            $resolved = ValueResolver::resolve($row, $fieldMap, $attributeTypes);

            // Tvrdo pravilo: bez vrednosti kljuca se NE kreira. CI bez
            // kljuca ne moze vise da se prepozna, pa bi svaki sledeci
            // prolaz pravio novi duplikat pored njega.
            if ($ci === null && $resolved['correlation'] === null) {
                $skipped[] = self::skip($row, 'nema vrednosti ključa spajanja — CI bez ključa se ne kreira');

                continue;
            }

            $action = $ci === null
                ? self::create($row, $resolved, $typeSlug, $sourceKind)
                : self::update($row, $ci, $link, $resolved, $typeSlug, $sourceKind, $attributeTypes);

            if ($action === null) {
                continue;
            }

            $actions[] = $action;
        }

        return [
            'actions' => $actions,
            'skipped' => $skipped,
            'counts'  => [
                'inventory' => count($inventory),
                'create'    => count(array_filter($actions, static fn (array $a): bool => $a['action'] === 'create')),
                'update'    => count(array_filter($actions, static fn (array $a): bool => $a['action'] === 'update')),
                'edited'    => count(array_filter($actions, static fn (array $a): bool => ($a['edited'] ?? []) !== [])),
                'skipped'   => count($skipped),
            ],
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Razlog zbog kog se za ovu vrstu ne moze upisivati, ili null.
     *
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private static function blocked(?string $typeSlug, array $fieldMap, string $sourceKind, bool $creating): ?string
    {
        if ($typeSlug === null || $typeSlug === '') {
            // Kod virtuelnih masina ovo nije stvar urednosti: izmereno je da
            // POST bez virtual_asset_type pukne sa HTTP 500.
            return 'tip CI-ja nije izabran u Mapiranju';
        }

        if (!$creating) {
            return null;
        }

        foreach (FieldCatalog::relations($sourceKind) as $key => [, , , $required]) {
            if (!$required) {
                continue;
            }

            $rule = $fieldMap['relation:' . $key] ?? null;

            if ($rule === null || ($rule['constant_value'] ?? '') === '') {
                return "veza „{$key}” je obavezna pri kreiranju, a nije podešena";
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{fields: list<array<string, mixed>>, skipped: list<array<string, string>>, correlation: ?array<string, mixed>} $resolved
     * @return array<string, mixed>
     */
    private static function create(array $row, array $resolved, string $typeSlug, string $sourceKind): array
    {
        $body    = [self::typeField($sourceKind) => $typeSlug];
        $changes = [];

        foreach ($resolved['fields'] as $field) {
            // Pri kreiranju ucestvuju sva pravila osim „ne diraj", koje je
            // vec otpalo u razresavanju.
            self::put($body, $field);

            $changes[] = ['ref' => $field['ref'], 'from' => null, 'to' => $field['value']];
        }

        return [
            'action'      => 'create',
            'source'      => $row,
            'ci'          => null,
            'body'        => $body,
            'changes'     => $changes,
            'edited'      => [],
            'unresolved'  => $resolved['skipped'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ci
     * @param array<string, mixed>|null $link
     * @param array{fields: list<array<string, mixed>>, skipped: list<array<string, string>>, correlation: ?array<string, mixed>} $resolved
     * @param array<string, string> $attributeTypes
     * @return array<string, mixed>|null
     */
    private static function update(
        array $row,
        array $ci,
        ?array $link,
        array $resolved,
        string $typeSlug,
        string $sourceKind,
        array $attributeTypes,
    ): ?array {
        $payload = json_decode((string) ($ci['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        $pushed = self::pushed($link);

        $changes = [];
        $edited  = [];

        foreach ($resolved['fields'] as $field) {
            // Kljuc spajanja i ostala create_only polja se ne preispituju:
            // upisuju se jednom i od tada pripadaju CI-ju.
            if ($field['policy'] === 'create_only') {
                continue;
            }

            $current = self::current($payload, $field);

            if ($field['policy'] === 'if_empty' && !self::isEmpty($current)) {
                continue;
            }

            if (self::same($current, $field['value'], $field['kind'])) {
                continue;
            }

            // Zateceno se razlikuje i od onoga sto smo poslednji put poslali:
            // znaci da je neko dirao polje rukom. Pravilo i dalje odlucuje —
            // ovo je da se vidi sta se gazi.
            if ($pushed !== null
                && array_key_exists($field['ref'], $pushed)
                && !self::same($current, $pushed[$field['ref']], $field['kind'])) {
                $edited[] = ['ref' => $field['ref'], 'current' => $current, 'pushed' => $pushed[$field['ref']]];
            }

            $changes[] = ['ref' => $field['ref'], 'from' => $current, 'to' => $field['value']];
        }

        if ($changes === []) {
            return null;
        }

        return [
            'action'     => 'update',
            'source'     => $row,
            'ci'         => $ci,
            'body'       => self::updateBody($payload, $resolved, $changes, $typeSlug, $sourceKind, $attributeTypes),
            'changes'    => $changes,
            'edited'     => $edited,
            'unresolved' => $resolved['skipped'],
        ];
    }

    /**
     * Telo PUT-a: zateceni CI, pa preko njega izmene.
     *
     * Kompletna mapa attribute_values je obavezna — izmereno je da PUT brise
     * sve atribute koje ne posalje, a medju njima bi bio i kljuc spajanja.
     * Zato se prvo prepisu zatecene EAV vrednosti, pa se tek onda primene
     * nase.
     *
     * Tip i lokacija idu uvek: tip zato sto bez njega server pada, lokacija
     * zato sto je UpdateVirtualAsset trazi kao obaveznu.
     *
     * @param array<string, mixed> $payload
     * @param array{fields: list<array<string, mixed>>, skipped: list<array<string, string>>, correlation: ?array<string, mixed>} $resolved
     * @param list<array<string, mixed>> $changes
     * @param array<string, string> $attributeTypes
     * @return array<string, mixed>
     */
    private static function updateBody(
        array $payload,
        array $resolved,
        array $changes,
        string $typeSlug,
        string $sourceKind,
        array $attributeTypes,
    ): array {
        $body = [
            self::typeField($sourceKind) => $typeSlug,
            'name'                       => (string) ($payload['name'] ?? ''),
        ];

        $location = $payload['location']['slug'] ?? null;

        if ($location !== null) {
            $body['location'] = (string) $location;
        }

        // Zatecene EAV vrednosti prvo, u celini.
        $body['attribute_values'] = self::existingAttributes($payload, $attributeTypes);

        $changed = array_column($changes, 'ref');

        foreach ($resolved['fields'] as $field) {
            // Kljuc i ostala create_only polja se ne menjaju, ali EAV kljuc
            // MORA da ostane u telu — i ostaje, jer je vec prepisan iz
            // zatecenih vrednosti iznad.
            if (!in_array($field['ref'], $changed, true)) {
                continue;
            }

            self::put($body, $field);
        }

        return $body;
    }

    /**
     * Zatecene EAV vrednosti u oblik za upis: mapa {slug: vrednost}.
     *
     * Cita se niz redova, salje mapa — oblik za citanje i oblik za upis nisu
     * isti. Red bez vrednosti se izostavlja: prazan atribut nema sta da se
     * vraca, a slanje null-a bi moglo da znaci brisanje.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $attributeTypes
     * @return array<string, mixed>
     */
    private static function existingAttributes(array $payload, array $attributeTypes): array
    {
        $values = [];

        foreach ((array) ($payload['attribute_values'] ?? []) as $rowValue) {
            if (!is_array($rowValue)) {
                continue;
            }

            $slug  = (string) ($rowValue['attribute']['slug'] ?? '');
            $value = $rowValue['value'] ?? null;

            if ($slug === '' || $value === null) {
                continue;
            }

            // Visestruki izbor se cita kao niz objekata {id, value}, a oblik
            // za upis nije izmeren. Takav atribut se ne dira — ali se ni ne
            // izostavlja, jer bi ga PUT obrisao. Jedino bezbedno je ne
            // upisivati CI koji ga ima.
            if (is_array($value)) {
                $values[$slug] = self::selectValues($value);

                continue;
            }

            $values[$slug] = $value;
        }

        return $values;
    }

    /**
     * @param list<mixed>|array<string, mixed> $value
     * @return list<string>
     */
    private static function selectValues(array $value): array
    {
        $out = [];

        foreach ($value as $item) {
            $out[] = is_array($item) ? (string) ($item['value'] ?? '') : (string) $item;
        }

        return array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Zatecena vrednost jednog cilja iz procitanog CI-ja.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $field
     */
    private static function current(array $payload, array $field): mixed
    {
        $key = (string) $field['key'];

        return match ($field['kind']) {
            'relation' => self::currentRelation($payload, $key),
            'eav'      => self::currentAttribute($payload, $key),
            default    => $payload[$key] ?? null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return string|list<string>|null
     */
    private static function currentRelation(array $payload, string $key): string|array|null
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return null;
        }

        // Visestruka veza je niz objekata sa slugom.
        if (array_is_list((array) $value)) {
            $slugs = [];

            foreach ((array) $value as $item) {
                if (is_array($item) && isset($item['slug'])) {
                    $slugs[] = (string) $item['slug'];
                }
            }

            return $slugs;
        }

        return isset($value['slug']) ? (string) $value['slug'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function currentAttribute(array $payload, string $slug): mixed
    {
        foreach ((array) ($payload['attribute_values'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['attribute']['slug'] ?? '') === $slug) {
                return $row['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Da li su zatecena i zeljena vrednost iste.
     *
     * Nativne se porede OBREZANE, jer ih server trimuje pri upisu: poslato
     * „ ESX-01 " vrati se kao „ESX-01", i bez ovoga bi svaki prolaz video
     * razliku koju je sam server napravio.
     *
     * EAV se porede doslovno — te server ne dira.
     */
    private static function same(mixed $current, mixed $desired, string $kind): bool
    {
        if (is_array($current) || is_array($desired)) {
            $a = array_map(strval(...), (array) $current);
            $b = array_map(strval(...), (array) $desired);

            sort($a);
            sort($b);

            return $a === $b;
        }

        if ($kind === 'eav') {
            // Broj i string istog sadrzaja nisu isto za ===, a jesu za
            // AssetOPS: integer atribut se cita kao broj.
            return is_int($desired) || is_float($desired)
                ? is_numeric($current) && (float) $current === (float) $desired
                : $current === $desired;
        }

        return trim((string) ($current ?? '')) === trim((string) ($desired ?? ''));
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    /**
     * Ono sto je SyncOPS poslednji put poslao, ako je zapamceno.
     *
     * Kolonu puni faza upisa. Dok je nema, poredjenje je dvostrano i rucna
     * izmena se ne prepoznaje — prikaz je tada manje tacan, ali odluka
     * ostaje ista, jer o njoj odlucuje pravilo upisa.
     *
     * @param array<string, mixed>|null $link
     * @return array<string, mixed>|null
     */
    private static function pushed(?array $link): ?array
    {
        $raw = $link['pushed_payload'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $field
     */
    private static function put(array &$body, array $field): void
    {
        if ($field['kind'] === 'eav') {
            $body['attribute_values'] ??= [];
            $body['attribute_values'][$field['key']] = $field['value'];

            return;
        }

        $body[$field['key']] = $field['value'];
    }

    private static function typeField(string $sourceKind): string
    {
        return $sourceKind === FieldCatalog::KIND_VM ? 'virtual_asset_type' : 'asset_type';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function skip(array $row, string $reason): array
    {
        return ['source' => $row, 'reason' => $reason];
    }
}
