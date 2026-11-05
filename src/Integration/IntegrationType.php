<?php
// src/Integration/IntegrationType.php
//
// Spisak tipova integracija: sta svaki od njih jeste, kako se povezuje i
// kako izgleda njegova forma.
//
// Ovo je mesto na kom je do migracije 0009 bio ENUM u bazi. Razlika je u
// tome sto ENUM zna samo spisak dozvoljenih reci, a ovde se zna i KOME koja
// pripada: vCenter ne moze da dobije dijalekt AssetOPS-a, sto baza nije
// umela da spreci.
//
// Pored dijalekata, tip nosi i opis forme. Razlog: vCenter i AssetOPS se ne
// prijavljuju isto. vCenter trazi korisnicko ime i lozinku servisnog naloga,
// AssetOPS samo API kljuc — korisnickog imena nema. Bez ovog opisa bi forma
// morala da grana po tipu u sablonu, a to je mesto gde se takve razlike
// najbrze izgube.
//
// Dodavanje novog tipa je jedan unos u TYPES. Ne trazi migraciju.

declare(strict_types=1);

namespace SyncOps\Integration;

final class IntegrationType
{
    public const VCENTER  = 'vcenter';
    public const ASSETOPS = 'assetops';

    /**
     * Tip => sta je, kako se povezuje, kako izgleda forma.
     *
     * Kod dijalekta "label" ide u formu, gde ima mesta za objasnjenje, a
     * "short" u tabele, gde ga ima za nekoliko znakova.
     */
    private const TYPES = [
        self::VCENTER => [
            'label'   => 'VMware vCenter',
            'role'    => 'izvor',
            'default' => Integration::FLAVOR_SOAP,
            'flavors' => [
                Integration::FLAVOR_SOAP => [
                    'label' => 'SOAP /sdk — vSphere Web Services API (vim25)',
                    'short' => '/sdk',
                ],
            ],
            'form' => [
                'noun'                 => 'vCenter',
                'intro'                => 'Servisni nalog treba da ima samo Read-only rolu na root objektu, sa propagacijom.',
                'host_placeholder'     => 'vcenter.primer.rs',
                'default_port'         => 443,
                'has_username'         => true,
                'username_label'       => 'Korisničko ime',
                'username_placeholder' => 'syncops@vsphere.local',
                'secret_label'         => 'Lozinka',
                'secret_hint'          => '',
                'tls_hint'             => 'Isključeno radi sa self-signed sertifikatom, što je na vCenterima uobičajeno.',
            ],
        ],

        self::ASSETOPS => [
            'label'   => 'AssetOPS CMDB',
            'role'    => 'odrediste',
            'default' => Integration::FLAVOR_API_KEY,
            'flavors' => [
                Integration::FLAVOR_API_KEY => [
                    'label' => 'API ključ — Authorization: Api-Key',
                    'short' => 'api-key',
                ],
            ],
            'form' => [
                'noun'             => 'AssetOPS',
                'intro'            => 'API ključ nosi prava korisnika za kog je izdat. Mora da vidi sve što se sinhronizuje — '
                                    . 'zapis koji ključ ne vidi izgleda isto kao zapis koji ne postoji.',
                'host_placeholder' => 'assetops.primer.rs',
                'default_port'     => 443,
                // AssetOPS nema korisnicko ime: ceo kredencijal je kljuc.
                'has_username'     => false,
                'secret_label'     => 'API ključ',
                'secret_hint'      => 'Oblik je prefiks.tajna, sa tačkom. Dobija se na /api/current-user/own-api-keys/.',
                'tls_hint'         => 'Isključeno radi sa self-signed sertifikatom. Uključi kada instanca ima sertifikat kojem klijent veruje.',
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
     * Da li je tip izvor podataka ili odrediste sinhronizacije. Sluzi samo
     * za prikaz — ponasanje se po ovome ne grana.
     */
    public static function role(string $type): string
    {
        return self::TYPES[$type]['role'] ?? 'izvor';
    }

    /**
     * Opis forme za taj tip. Nepoznat tip dobija vCenter-ov opis, da se
     * forma ne srusi na nedostajucem kljucu.
     *
     * @return array<string, mixed>
     */
    public static function form(string $type): array
    {
        return self::TYPES[$type]['form'] ?? self::TYPES[self::VCENTER]['form'];
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
        return self::TYPES[$type]['default'] ?? Integration::FLAVOR_SOAP;
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
