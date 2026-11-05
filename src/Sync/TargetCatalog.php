<?php
// src/Sync/TargetCatalog.php
//
// Sta se sve moze mapirati za jednu vrstu izvora: veze, nativna polja, pa
// EAV atributi primenjivi na odgovarajuci entitet.
//
// Stoji izvan kontrolera jer nije priprema ekrana nego odluka: ono sto se
// ovde ne pojavi ne moze ni da se mapira, a sto se ne mapira ne upisuje se.
// Takva odluka mora da moze da se proveri bez podizanja ekrana.

declare(strict_types=1);

namespace SyncOps\Sync;

use SyncOps\Storage\AssetOpsRepository;

final class TargetCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forSourceKind(
        AssetOpsRepository $repo,
        int $integrationId,
        string $sourceKind,
    ): array {
        $map  = $repo->fieldMap($integrationId, $sourceKind);
        $rows = [];

        // Veze idu prve: bez lokacije i visibility grupe kreiranje CI-ja pada,
        // pa treba da budu prvo sto se vidi.
        foreach (FieldCatalog::relations($sourceKind) as $key => [$label, $listKind, $multi, $required]) {
            $row = self::target('relation', $key, $label, '', [], $map);

            $row['options']  = $repo->options($integrationId, $listKind);
            $row['multi']    = $multi;
            $row['required'] = $required;
            $row['fixed']    = self::decodeFixed((string) ($row['constant_value'] ?? ''), $multi);

            $rows[] = $row;
        }

        foreach (FieldCatalog::nativeFields($sourceKind) as $key => [$label, $type, $valueMode]) {
            $row = self::target('native', $key, $label, $type, [], $map);

            // Polje bez parnjaka u vCenteru upisuje se rukom.
            $row['value_mode'] = $valueMode;
            $row['fixed']      = $valueMode === 'constant' && (string) ($row['constant_value'] ?? '') !== ''
                ? [(string) $row['constant_value']]
                : [];

            $rows[] = $row;
        }

        foreach ($repo->attributesForEntity($integrationId, FieldCatalog::entity($sourceKind)) as $attribute) {
            $row = self::target(
                'eav',
                (string) $attribute['slug'],
                (string) $attribute['name'],
                (string) ($attribute['attribute_type'] ?? ''),
                (array) ($attribute['possible_values'] ?? []),
                $map
            );

            // Atribut kog vise nema u AssetOPS-u.
            //
            // Prikazuje se samo dok na njega jos pokazuje mapiranje — da se
            // vidi sta ce se izgubiti i da ga cuvanje obrise. Cim mapiranja
            // nema, nema ni razloga da stoji na ekranu: atribut ne postoji,
            // pa se u njega ne moze ni mapirati.
            $missing = ($attribute['missing'] ?? false) === true;

            if ($missing && !isset($map[$row['ref']])) {
                continue;
            }

            $row['missing'] = $missing;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $map
     * @return array<string, mixed>
     */
    private static function target(
        string $kind,
        string $key,
        string $label,
        string $type,
        array $possibleValues,
        array $map,
    ): array {
        $ref     = $kind . ':' . $key;
        $current = $map[$ref] ?? null;

        return [
            'ref'             => $ref,
            'kind'            => $kind,
            'value_mode'      => $kind === 'relation' ? 'list' : 'source',
            'options'         => [],
            'multi'           => false,
            'required'        => false,
            'fixed'           => [],
            'missing'         => false,
            'key'             => $key,
            'label'           => $label,
            'type'            => $type,
            'possible_values' => $possibleValues,
            'source_field'    => $current['source_field'] ?? null,
            'constant_value'  => $current['constant_value'] ?? null,
            'write_policy'    => $current['write_policy'] ?? 'never',
            'is_correlation'  => $current['is_correlation'] ?? false,
        ];
    }

    /**
     * Zapamcena vrednost veze: jedan slug, ili niz slugova kod visestruke.
     *
     * @return list<string>
     */
    private static function decodeFixed(string $raw, bool $multi): array
    {
        if ($raw === '') {
            return [];
        }

        if (!$multi) {
            return [$raw];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_map(strval(...), $decoded))
            : [$raw];
    }
}
