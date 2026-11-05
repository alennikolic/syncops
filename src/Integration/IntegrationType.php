<?php
// src/Integration/IntegrationType.php
//
// Spisak tipova integracija i dijalekata koji vaze za svaki od njih.
//
// Ovo je mesto na kom je do migracije 0009 bio ENUM u bazi. Razlika je u
// tome sto ENUM zna samo spisak dozvoljenih reci, a ovde se zna i KOME koja
// pripada: vCenter ne moze da dobije dijalekt Kasperskyja, sto baza nije
// umela da spreci.
//
// Dodavanje novog tipa integracije je jedan unos u TYPES. Dodavanje novog
// dijalekta postojecem tipu je jedan red. Nijedno ne trazi migraciju.
//
// Nepoznat tip se ne tretira kao greska: vraca prazan spisak dijalekata, a
// nepoznat dijalekt se prikazuje onakav kakav stoji u bazi. Tako red koji je
// ostao iza uklonjene integracije ne obori ekran.

declare(strict_types=1);

namespace SyncOps\Integration;

final class IntegrationType
{
    public const VCENTER = 'vcenter';

    /** Grupe u padajucem spisku, redom kojim se prikazuju. */
    public const GROUP_MAIN   = 'Protokol';
    public const GROUP_MANUAL = 'Ručno biranje REST dijalekta';

    /**
     * Tip => opis, podrazumevan dijalekt i dijalekti.
     *
     * Kod svakog dijalekta "label" ide u formu, gde ima mesta za objasnjenje,
     * a "short" u tabele, gde ga ima za nekoliko znakova.
     *
     * Zasto su dijalekti podeljeni u grupe: koji REST dijalekt vCenter govori
     * nije odluka nego posledica njegove verzije, i program to sam utvrdi.
     * Jedina stvarna odluka je REST ili SOAP. Zato "auto" stoji kao obican
     * REST, a dva dijalekta se nude samo kao izlaz u nuzdi — kada detekcija
     * iz nekog razloga ne radi.
     */
    private const TYPES = [
        self::VCENTER => [
            'label'   => 'VMware vCenter',
            'default' => Integration::FLAVOR_AUTO,
            'flavors' => [
                Integration::FLAVOR_AUTO => [
                    'label' => 'REST — dijalekt se prepoznaje sam',
                    'short' => 'REST',
                    'group' => self::GROUP_MAIN,
                ],
                Integration::FLAVOR_SOAP => [
                    'label' => 'SOAP /sdk — jedini daje templejte i hardver hostova',
                    'short' => '/sdk',
                    'group' => self::GROUP_MAIN,
                ],
                Integration::FLAVOR_API_V2 => [
                    'label' => '/api — vSphere 7.0 U2 i noviji',
                    'short' => '/api',
                    'group' => self::GROUP_MANUAL,
                ],
                Integration::FLAVOR_REST_LEGACY => [
                    'label' => '/rest — vSphere 6.7 do 7.0',
                    'short' => '/rest',
                    'group' => self::GROUP_MANUAL,
                ],
            ],
        ],
    ];

    /**
     * Svi tipovi: kljuc => citljiv naziv.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $type): string => $type['label'],
            self::TYPES
        );
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /**
     * Dijalekti jednog tipa, za padajuci spisak u formi.
     *
     * @return array<string, string> dijalekt => puni opis
     */
    public static function flavors(string $type): array
    {
        return array_map(
            static fn (array $flavor): string => $flavor['label'],
            self::TYPES[$type]['flavors'] ?? []
        );
    }

    /**
     * Dijalekti grupisani za padajuci spisak.
     *
     * @return array<string, array<string, string>> naziv grupe => dijalekt => opis
     */
    public static function flavorGroups(string $type): array
    {
        $groups = [];

        foreach (self::TYPES[$type]['flavors'] ?? [] as $key => $flavor) {
            $groups[$flavor['group'] ?? self::GROUP_MAIN][$key] = $flavor['label'];
        }

        return $groups;
    }

    /**
     * Kratka oznaka dijalekta, za tabele. Nepoznat se vraca kakav jeste.
     */
    public static function flavorLabel(string $type, ?string $flavor): string
    {
        if ($flavor === null || $flavor === '') {
            return '';
        }

        return self::TYPES[$type]['flavors'][$flavor]['short'] ?? $flavor;
    }

    public static function defaultFlavor(string $type): string
    {
        return self::TYPES[$type]['default'] ?? Integration::FLAVOR_AUTO;
    }

    /**
     * Sme li ovaj tip integracije da govori ovim dijalektom.
     *
     * Ovo je provera koju ENUM nije umeo: ne samo da li rec postoji, nego i
     * da li pripada ovom tipu.
     */
    public static function isValidFlavor(string $type, string $flavor): bool
    {
        return isset(self::TYPES[$type]['flavors'][$flavor]);
    }
}
