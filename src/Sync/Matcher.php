<?php
// src/Sync/Matcher.php
//
// Koji objekat iz vCentera odgovara kom CI-ju u AssetOPS-u.
//
// Racun bez ijednog upisa i bez ijednog poziva: ulaz je inventar, procitani
// CI-jevi i postojece veze, izlaz je predlog. Zato je ovde ceo, odvojen od
// baze i od ekrana — spajanje je mesto gde greska pravi duplikate, pa mora
// da se moze proveriti u testu, a ne samo videti na ekranu.
//
// TRI JACINE, I TO NIJE KOZMETIKA
//
//   zapamceno    veza vec postoji; ne preispituje se svaki put
//   jako         vrednost kljuca spajanja se poklopila — jedino pouzdano
//   slabo        isto ime ili isti serijski broj; PREDLOG, ne veza
//
// Slabo spajanje postoji samo zbog prvog prolaza. Dok SyncOPS nije nista
// upisao, nijedan CI ne nosi nas kljuc, pa bi bez njega sve izgledalo kao
// novo i napravili bismo duplikat svake masine koja vec postoji. Posle prvog
// prolaza slabo spajanje prakticno nestaje samo od sebe.
//
// Zato slabo nikad ne postaje veza bez coveka. Ime nije jedinstveno, a
// serijski broj na testnoj instanci dele 14 od 17 masina.
//
// DVOSMISLENO NIJE SLABO
//
// Kada jedan objekat odgovara na dva CI-ja, ili dva objekta na isti CI, to
// nije predlog nego sukob. Predlog bi covek potvrdio na brzinu; sukob trazi
// da se pogleda.

declare(strict_types=1);

namespace SyncOps\Sync;

final class Matcher
{
    /**
     * Predlog spajanja za jednu vrstu izvora.
     *
     * $sourceField je kolona inventara koja nosi vrednost kljuca spajanja —
     * ono sto mapiranje upisuje u AssetOPS kao kljuc. Bez nje jakog spajanja
     * nema i sve ostaje na slabom.
     *
     * $weakFields su kolone kojima se pokusava slabo spajanje, redom. Ime
     * ide prvo; kod hostova za njim serijski broj.
     *
     * @param list<array<string, mixed>>   $inventory redovi iz vc_vm / vc_host
     * @param list<array<string, mixed>>   $cis       redovi iz ao_ci
     * @param array<int, array<string, mixed>> $links postojece veze, po source_id
     * @param list<string>                 $weakFields
     *
     * @return array{
     *     linked: list<array<string, mixed>>,
     *     strong: list<array<string, mixed>>,
     *     weak: list<array<string, mixed>>,
     *     unmatched: list<array<string, mixed>>,
     *     orphans: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     broken: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public static function plan(
        array $inventory,
        array $cis,
        array $links,
        ?string $sourceField,
        array $weakFields = ['name'],
    ): array {
        $byUuid = [];
        $byKey  = [];
        $byName = [];

        foreach ($cis as $ci) {
            $byUuid[(string) $ci['uuid']] = $ci;

            $key = $ci['correlation_value'] ?? null;

            if ($key !== null) {
                $byKey[self::fold((string) $key)][] = $ci;
            }

            $byName[self::fold((string) $ci['name'])][] = $ci;
        }

        $result = [
            'linked' => [], 'strong' => [], 'weak' => [], 'unmatched' => [],
            'orphans' => [], 'conflicts' => [], 'broken' => [],
        ];

        /** @var array<string, array<string, mixed>> $claimed uuid => ko ga je uzeo */
        $claimed = [];
        $taken   = [];

        // 1. Zapamcene veze idu u posebnom prolazu, PRE svih ostalih.
        //
        // Ne zbog urednosti: da bi zapamcena veza uvek pobedila predlog,
        // bez obzira na redosled citanja inventara. Da se resava u jednom
        // prolazu, masina koja dolazi ranije mogla bi slabim spajanjem da
        // uzme CI koji vec pripada nekome dalje u spisku — i upis bi pao na
        // jedinstvenom indeksu, ili, gore, presao na pogresan CI.
        //
        // Veza se pritom ne preispituje: covek je mozda rucno spojio nesto
        // sto se po kljucu ne poklapa, i to je njegova odluka, ne greska.
        $remaining = [];

        foreach ($inventory as $row) {
            $link = $links[(int) $row['id']] ?? null;

            if ($link === null) {
                $remaining[] = $row;

                continue;
            }

            $uuid = (string) $link['ci_uuid'];

            if (!isset($byUuid[$uuid])) {
                // CI je nestao iz AssetOPS-a. Veza se ne brise sama: mozda
                // je CI obrisan greskom, a mozda namerno.
                $result['broken'][] = ['source' => $row, 'link' => $link];

                continue;
            }

            $claimed[$uuid] = $row;
            $taken[$uuid]   = true;

            $result['linked'][] = [
                'source' => $row,
                'ci'     => $byUuid[$uuid],
                'link'   => $link,
                // Vrednost kljuca se u medjuvremenu razisla: CI nosi nesto
                // trece. Veza ostaje, ali se prijavljuje.
                'drift'  => self::drifted($row, $byUuid[$uuid], $sourceField),
            ];
        }

        foreach ($remaining as $row) {
            // 2. Jako spajanje: vrednost kljuca.
            $value = $sourceField === null ? null : self::value($row, $sourceField);

            if ($value !== null && isset($byKey[self::fold($value)])) {
                $candidates = $byKey[self::fold($value)];

                if (count($candidates) > 1) {
                    $result['conflicts'][] = [
                        'source' => $row,
                        'cis'    => $candidates,
                        'reason' => 'ista vrednost ključa na više CI-jeva',
                    ];

                    continue;
                }

                $ci   = $candidates[0];
                $uuid = (string) $ci['uuid'];

                if (isset($claimed[$uuid])) {
                    $result['conflicts'][] = [
                        'source' => $row,
                        'cis'    => [$ci],
                        'other'  => $claimed[$uuid],
                        'reason' => 'isti CI odgovara na dva objekta',
                    ];

                    continue;
                }

                $claimed[$uuid] = $row;
                $taken[$uuid]   = true;

                $result['strong'][] = ['source' => $row, 'ci' => $ci, 'matched_by' => 'correlation'];

                continue;
            }

            // 3. Slabo spajanje. Samo ako CI jos nije uzet jakim.
            $proposal = self::weak($row, $weakFields, $byName, $cis, $claimed);

            if ($proposal === null) {
                $result['unmatched'][] = ['source' => $row];

                continue;
            }

            if (isset($proposal['reason'])) {
                $result['conflicts'][] = $proposal;

                continue;
            }

            $uuid           = (string) $proposal['ci']['uuid'];
            $claimed[$uuid] = $row;
            $taken[$uuid]   = true;

            $result['weak'][] = $proposal;
        }

        // 4. CI-jevi koje niko nije uzeo, a nose nas kljuc. Ti su ili ostaci
        //    od obrisanih masina ili znak da spajanje nije pronaslo ono sto
        //    je trebalo. Ostali CI-jevi tog entiteta su tudji posao i ne
        //    prijavljuju se.
        foreach ($cis as $ci) {
            if (($ci['correlation_value'] ?? null) === null || isset($taken[(string) $ci['uuid']])) {
                continue;
            }

            $result['orphans'][] = ['ci' => $ci];
        }

        $result['counts'] = [
            'inventory' => count($inventory),
            'cis'       => count($cis),
            'linked'    => count($result['linked']),
            'strong'    => count($result['strong']),
            'weak'      => count($result['weak']),
            'unmatched' => count($result['unmatched']),
            'orphans'   => count($result['orphans']),
            'conflicts' => count($result['conflicts']),
            'broken'    => count($result['broken']),
        ];

        return $result;
    }

    // -----------------------------------------------------------------

    /**
     * Slabo spajanje po imenu, pa po ostalim poljima.
     *
     * Vraca predlog, sukob (ima 'reason') ili null kada kandidata nema.
     *
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @param array<string, list<array<string, mixed>>> $byName
     * @param list<array<string, mixed>> $cis
     * @param array<string, array<string, mixed>> $claimed
     * @return array<string, mixed>|null
     */
    private static function weak(
        array $row,
        array $fields,
        array $byName,
        array $cis,
        array $claimed,
    ): ?array {
        foreach ($fields as $field) {
            $value = self::value($row, $field);

            if ($value === null) {
                continue;
            }

            $candidates = $field === 'name'
                ? ($byName[self::fold($value)] ?? [])
                : self::byField($cis, 'serial_number', $value);

            // CI koji je vec uzet jakim spajanjem nije kandidat: jako uvek
            // pobedjuje slabo, bez obzira na redosled citanja.
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $ci): bool => !isset($claimed[(string) $ci['uuid']])
            ));

            if ($candidates === []) {
                continue;
            }

            if (count($candidates) > 1) {
                return [
                    'source' => $row,
                    'cis'    => $candidates,
                    'reason' => $field === 'name'
                        ? 'više CI-jeva nosi isto ime'
                        : 'više CI-jeva nosi isti serijski broj',
                ];
            }

            return [
                'source'     => $row,
                'ci'         => $candidates[0],
                'matched_by' => $field === 'name' ? 'name' : 'serial',
                'on'         => $value,
            ];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $cis
     * @return list<array<string, mixed>>
     */
    private static function byField(array $cis, string $field, string $value): array
    {
        $folded = self::fold($value);

        return array_values(array_filter($cis, static function (array $ci) use ($field, $folded): bool {
            $own = $ci[$field] ?? null;

            return $own !== null && self::fold((string) $own) === $folded;
        }));
    }

    /**
     * Da li se vrednost kljuca razisla od onoga sto CI nosi.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ci
     */
    private static function drifted(array $row, array $ci, ?string $sourceField): bool
    {
        if ($sourceField === null) {
            return false;
        }

        $ours   = self::value($row, $sourceField);
        $theirs = $ci['correlation_value'] ?? null;

        // Prazno na njihovoj strani nije razlaz nego jos neupisana vrednost.
        if ($ours === null || $theirs === null) {
            return false;
        }

        return self::fold($ours) !== self::fold((string) $theirs);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function value(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Poredjenje bez razlike u velicini slova i rubnim razmacima.
     *
     * Razmaci se skidaju jer ih i AssetOPS skida: izmereno je da server
     * trimuje nativna polja pri upisu, pa bi poslato „ ESX-01 " i vraceno
     * „ESX-01" inace izgledali kao dve razlicite masine.
     */
    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
