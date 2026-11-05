<?php
// src/Sync/CiOverview.php
//
// Brojevi koji odlucuju da li sinhronizacija sme da pocne, iz procitanih
// CI-jeva.
//
// Stoji izvan kontrolera jer je ovo zaista racun, a ne priprema ekrana: isti
// brojevi trebaju i prikazu i CLI-ju, a greska u njima ne bi bila kozmeticka.
//
// Najvazniji je "mismatched": CI koji nosi nasu vrednost kljuca spajanja a
// nije mapiranog tipa. To je i razlog zasto se cita ceo entitet a ne samo
// mapirani tip — filtrirano citanje takav CI ne bi videlo, SyncOPS ga ne bi
// prepoznao kao postojeci i napravio bi duplikat pored njega.

declare(strict_types=1);

namespace SyncOps\Sync;

final class CiOverview
{
    /**
     * @param list<array<string, mixed>> $rows redovi iz ao_ci
     * @return array{
     *     counts: array{total: int, with_key: int, of_type: int, mismatched: int, no_type: int},
     *     mismatched: list<array<string, mixed>>
     * }
     */
    public static function summarize(array $rows, ?string $typeSlug): array
    {
        $counts = [
            'total'      => count($rows),
            'with_key'   => 0,
            'of_type'    => 0,
            'mismatched' => 0,
            'no_type'    => 0,
        ];

        $mismatched = [];

        foreach ($rows as $row) {
            $hasKey = ($row['correlation_value'] ?? null) !== null;
            $type   = $row['type_slug'] ?? null;

            if ($hasKey) {
                $counts['with_key']++;
            }

            if ($type === null) {
                $counts['no_type']++;
            }

            // Bez izabranog tipa nema sa cim da se uporedi: tada "mapiranog
            // tipa" i "drugog tipa" nisu nula nego nepoznato, pa se ne broje.
            if ($typeSlug === null) {
                continue;
            }

            if ($type === $typeSlug) {
                $counts['of_type']++;

                continue;
            }

            // Tip se razlikuje. Prijavljuje se samo ako CI nosi nas kljuc —
            // ostali CI-jevi tog entiteta su tudji posao i nisu neusklada.
            if ($hasKey) {
                $counts['mismatched']++;
                $mismatched[] = $row;
            }
        }

        return ['counts' => $counts, 'mismatched' => $mismatched];
    }
}
