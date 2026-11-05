<?php
// src/Sync/ValueResolver.php
//
// Sta bi SyncOPS upisao u jedan CI, za jedan objekat iz vCentera.
//
// Levo je red iz inventara, desno mapiranje; izlaz je spisak zeljenih
// vrednosti po cilju, svaka sa svojim pravilom upisa. Poredjenje sa
// zatecenim stanjem radi Planner — ovde se samo racuna sta BI se upisalo.
//
// PRETVARANJE TIPOVA NIJE KOZMETIKA
//
// EAV atribut tipa integer prima BROJ, ne string: izmereno je da ga server
// vraca kao JSON broj. Ako se posalje "512" pa procita 512, poredjenje bi
// svaki put videlo razliku i upisivalo u nedogled. Zato se vrednost
// pretvara po tipu CILJA, ne po tipu izvora.
//
// Polje koje ne moze da se pretvori se NE salje i nosi razlog. Tiho
// preskakanje je dogovoreno za ceo objekat, ali razlog mora da postoji —
// inace se ne zna zasto podatka nema.

declare(strict_types=1);

namespace SyncOps\Sync;

final class ValueResolver
{
    /** Tipovi EAV atributa koje umemo da upisemo. */
    private const WRITABLE_TYPES = ['char', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

    /**
     * Zeljene vrednosti za jedan objekat.
     *
     * $fieldMap je mapiranje po cilju, onako kako ga vraca repozitorijum:
     * "native:name" => [source_field, constant_value, write_policy,
     * is_correlation].
     *
     * $attributeTypes je slug EAV atributa => tip, iz prikupljenih
     * definicija. Atribut kog nema u spisku se preskace: ne zna mu se tip,
     * a pogadjanje bi znacilo upis u pogresnom obliku.
     *
     * @param array<string, mixed>                     $row
     * @param array<string, array<string, mixed>>      $fieldMap
     * @param array<string, string>                    $attributeTypes
     *
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     skipped: list<array{ref: string, reason: string}>,
     *     correlation: ?array<string, mixed>
     * }
     */
    public static function resolve(array $row, array $fieldMap, array $attributeTypes = []): array
    {
        $fields      = [];
        $skipped     = [];
        $correlation = null;

        foreach ($fieldMap as $ref => $rule) {
            [$kind, $key] = array_pad(explode(':', (string) $ref, 2), 2, '');

            if ($key === '') {
                continue;
            }

            $policy        = (string) ($rule['write_policy'] ?? 'never');
            $isCorrelation = ($rule['is_correlation'] ?? false) === true;

            // "Ne diraj" ne ulazi ni u poredjenje. Kljuc spajanja je izuzetak
            // samo utoliko sto je uvek create_only, sto cuva repozitorijum.
            if ($policy === 'never' && !$isCorrelation) {
                continue;
            }

            $raw = self::raw($row, $rule);

            if ($raw === null) {
                $skipped[] = ['ref' => (string) $ref, 'reason' => 'izvor je prazan'];

                continue;
            }

            $value = match ($kind) {
                'relation' => self::relation($raw),
                'eav'      => self::typed($raw, $attributeTypes[$key] ?? null),
                default    => self::native($raw),
            };

            if ($value === null) {
                $skipped[] = [
                    'ref'    => (string) $ref,
                    'reason' => $kind === 'eav'
                        ? self::eavReason($key, $attributeTypes)
                        : 'vrednost nije upotrebljiva za ovo polje',
                ];

                continue;
            }

            $field = [
                'ref'            => (string) $ref,
                'kind'           => $kind,
                'key'            => $key,
                'value'          => $value,
                'policy'         => $isCorrelation ? 'create_only' : $policy,
                'is_correlation' => $isCorrelation,
            ];

            $fields[] = $field;

            if ($isCorrelation) {
                $correlation = $field;
            }
        }

        return ['fields' => $fields, 'skipped' => $skipped, 'correlation' => $correlation];
    }

    // -----------------------------------------------------------------

    /**
     * Sirova vrednost: iz kolone inventara ili iz fiksne vrednosti.
     *
     * Red daje ILI jedno ILI drugo — fiksnu imaju veze i polja kojima u
     * vCenteru nema parnjaka. Prazan string je isto sto i nema vrednosti:
     * upisati prazno preko necega sto je covek uneo nije azuriranje nego
     * brisanje.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $rule
     */
    private static function raw(array $row, array $rule): mixed
    {
        $constant = $rule['constant_value'] ?? null;

        if ($constant !== null && $constant !== '') {
            return $constant;
        }

        $column = $rule['source_field'] ?? null;

        if ($column === null || $column === '') {
            return null;
        }

        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Veza: jedan slug, ili niz slugova kod visestruke.
     *
     * Visestruka je u bazi zapamcena kao JSON niz, jer jedno polje ne moze
     * da nosi vise vrednosti. Ovde se vraca u niz.
     *
     * @return string|list<string>|null
     */
    private static function relation(mixed $raw): string|array|null
    {
        if (is_array($raw)) {
            $slugs = array_values(array_filter(array_map(strval(...), $raw), static fn (string $s): bool => $s !== ''));

            return $slugs === [] ? null : $slugs;
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        if (str_starts_with($text, '[')) {
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                $slugs = array_values(array_filter(array_map(strval(...), $decoded), static fn (string $s): bool => $s !== ''));

                return $slugs === [] ? null : $slugs;
            }
        }

        return $text;
    }

    /**
     * Nativno polje. Uvek string — izmereno je da server nativne vrednosti
     * trimuje, pa se salju vec obrezane: tako poslato i procitano izgledaju
     * isto i poredjenje se smiruje.
     */
    private static function native(mixed $raw): ?string
    {
        if (is_bool($raw)) {
            return $raw ? 'da' : 'ne';
        }

        if (is_array($raw)) {
            return null;
        }

        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    /**
     * EAV vrednost u obliku koji tip atributa trazi.
     *
     * Za razliku od nativnih, EAV vrednosti server NE normalizuje — vraca ih
     * verbatim. Zato se ovde ne trimuje vise nego sto treba: ono sto se
     * posalje je tacno ono sto ce se procitati.
     */
    private static function typed(mixed $raw, ?string $type): string|int|float|bool|null
    {
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return null;
        }

        if (is_array($raw)) {
            return null;
        }

        return match ($type) {
            'integer'  => self::toInt($raw),
            'decimal'  => is_numeric($raw) ? (float) $raw : null,
            'boolean'  => self::toBool($raw),
            default    => self::text($raw),
        };
    }

    private static function toInt(mixed $raw): ?int
    {
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private static function toBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $text = mb_strtolower(trim((string) $raw));

        return match ($text) {
            '1', 'true', 'da', 'yes'  => true,
            '0', 'false', 'ne', 'no'  => false,
            default                   => null,
        };
    }

    private static function text(mixed $raw): ?string
    {
        if (is_bool($raw)) {
            return $raw ? 'da' : 'ne';
        }

        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, string> $attributeTypes
     */
    private static function eavReason(string $key, array $attributeTypes): string
    {
        $type = $attributeTypes[$key] ?? null;

        if ($type === null) {
            return 'atribut nije u prikupljenim definicijama — prikupi ponovo';
        }

        if (!in_array($type, self::WRITABLE_TYPES, true)) {
            return "tip „{$type}” se još ne upisuje";
        }

        return "vrednost ne odgovara tipu „{$type}”";
    }
}
