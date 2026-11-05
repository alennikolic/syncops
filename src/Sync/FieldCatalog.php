<?php
// src/Sync/FieldCatalog.php
//
// Sta se iz vCenter inventara moze upisati u AssetOPS, i u sta.
//
// Dva spiska u jednom fajlu jer se citaju zajedno — jedan je leva strana
// mapiranja, drugi desna:
//
//   SOURCES  polja iz vc_vm i vc_host koja mogu da popune nesto
//   NATIVE   nativna polja CI-ja u AssetOPS-u koja se mogu puniti
//
// Imena izvornih polja su imena KOLONA u inventaru, da se vrednost uzima bez
// prevodjenja. Kada se doda kolona (kao sto je nedavno dodat notes), ovde se
// dopisuje jedan red i odmah je ponudjena u mapiranju.
//
// "type" nije ukras: EAV atribut tipa integer prima BROJ, ne string, pa se
// pri upisu konvertuje po tipu izvora i po tipu atributa. Prikaz ga pokazuje
// da bi se videlo kada se broj mapira u tekstualni atribut.

declare(strict_types=1);

namespace SyncOps\Sync;

final class FieldCatalog
{
    public const KIND_VM   = 'vm';
    public const KIND_HOST = 'host';

    /** Entitet u AssetOPS-u na koji se vrsta preslikava. */
    public const ENTITY = [
        self::KIND_VM   => 'virtual-asset',
        self::KIND_HOST => 'asset',
    ];

    /**
     * Polja iz vCenter inventara. Kljuc je ime kolone u vc_vm / vc_host.
     *
     * Grupe sluze samo da padajuci spisak bude pregledan.
     */
    private const SOURCES = [
        self::KIND_VM => [
            'instance_uuid'        => ['Identifikatori', 'instance_uuid', 'text'],
            'bios_uuid'            => ['Identifikatori', 'bios_uuid', 'text'],
            'moref'                => ['Identifikatori', 'moref (vm-1234)', 'text'],

            'name'                 => ['Osnovno', 'ime mašine', 'text'],
            'power_state'          => ['Osnovno', 'napajanje', 'text'],
            'is_template'          => ['Osnovno', 'da li je templejt', 'bool'],
            'notes'                => ['Osnovno', 'Notes iz vCentera', 'text'],

            'guest_os'             => ['Gost', 'OS podešen u vCenteru', 'text'],
            'guest_full_name'      => ['Gost', 'OS prijavljen iz gosta', 'text'],
            'guest_os_family'      => ['Gost', 'porodica OS-a', 'text'],
            'guest_hostname'       => ['Gost', 'hostname iz gosta', 'text'],
            'guest_ip_address'     => ['Gost', 'IP adresa', 'text'],

            'cpu_count'            => ['Resursi', 'vCPU', 'int'],
            'cpu_cores_per_socket' => ['Resursi', 'jezgara po soketu', 'int'],
            'memory_mib'           => ['Resursi', 'RAM u MiB', 'int'],
            'disk_count'           => ['Resursi', 'broj diskova', 'int'],
            'disk_capacity_bytes'  => ['Resursi', 'kapacitet diskova u bajtovima', 'int'],
            'nic_count'            => ['Resursi', 'broj mrežnih kartica', 'int'],
            'hardware_version'     => ['Resursi', 'verzija hardvera', 'text'],

            'host_name'            => ['Smeštaj', 'host', 'text'],
            'cluster_name'         => ['Smeštaj', 'klaster', 'text'],
            'datacenter_moref'     => ['Smeštaj', 'datacentar', 'text'],
            'integration_name'     => ['Smeštaj', 'naziv vCenter integracije', 'text'],
        ],

        self::KIND_HOST => [
            'hardware_uuid'    => ['Identifikatori', 'hardware_uuid', 'text'],
            'serial_number'    => ['Identifikatori', 'serijski broj', 'text'],
            'asset_tag'         => ['Identifikatori', 'inventarski broj', 'text'],
            'moref'            => ['Identifikatori', 'moref (host-123)', 'text'],

            'name'             => ['Osnovno', 'ime hosta', 'text'],
            'connection_state' => ['Osnovno', 'stanje veze', 'text'],
            'power_state'      => ['Osnovno', 'napajanje', 'text'],

            'vendor'           => ['Hardver', 'proizvođač', 'text'],
            'model'            => ['Hardver', 'model', 'text'],
            'bios_version'     => ['Hardver', 'verzija BIOS-a', 'text'],

            'cpu_model'        => ['Procesor i memorija', 'model procesora', 'text'],
            'cpu_sockets'      => ['Procesor i memorija', 'broj soketa', 'int'],
            'cpu_cores'        => ['Procesor i memorija', 'jezgara ukupno', 'int'],
            'cpu_threads'      => ['Procesor i memorija', 'niti ukupno', 'int'],
            'cpu_mhz'          => ['Procesor i memorija', 'takt u MHz', 'int'],
            'memory_bytes'     => ['Procesor i memorija', 'memorija u bajtovima', 'int'],

            'esxi_version'     => ['ESXi', 'verzija', 'text'],
            'esxi_build'       => ['ESXi', 'build', 'text'],

            'cluster_name'     => ['Smeštaj', 'klaster', 'text'],
            'datacenter_moref' => ['Smeštaj', 'datacentar', 'text'],
            'vm_count'         => ['Smeštaj', 'broj mašina na hostu', 'int'],
            'integration_name' => ['Smeštaj', 'naziv vCenter integracije', 'text'],
        ],
    ];

    /**
     * Nativna polja CI-ja u AssetOPS-u koja se mogu puniti iz vCentera.
     *
     * Namerno su izostavljena polja koja su VEZE (status, location, vendor,
     * asset_type, rack): one se ne salju kao tekst nego kao slug, i biraju se
     * u panelu Mapiranje iznad, gde postoji spisak dozvoljenih vrednosti.
     * Mapiranje slobodnog teksta na njih bi proizvelo slug koji ne postoji.
     */
    private const NATIVE = [
        self::KIND_VM => [
            'name'          => ['Naziv CI-ja', 'text'],
            'serial_number' => ['Serijski broj', 'text'],
            'description'   => ['Opis', 'text'],
        ],
        self::KIND_HOST => [
            'name'                => ['Naziv CI-ja', 'text'],
            'serial_number'       => ['Serijski broj', 'text'],
            'internal_identifier' => ['Interni identifikator', 'text'],
            'description'         => ['Opis', 'text'],
        ],
    ];

    /**
     * Veze: polja ciju vrednost ne daje vCenter nego spisak iz AssetOPS-a.
     *
     * Salju se kao SLUG, a kod visibility grupa kao niz slugova. Zato se u
     * tabeli ne nude izvorna polja iz vCentera nego prikupljene vrednosti —
     * slobodan tekst bi dao slug koji ne postoji.
     *
     * "required" znaci da bez njega POST pada: CreateVirtualAsset trazi
     * location i visibility_groups, CreateAsset jos i asset_type.
     *
     * Status NIJE obavezan, ali je ovde jer je prvo sto treba podesiti —
     * novi CI bez statusa u CMDB-u izgleda nedovrseno.
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     *      polje => [opis, vrsta referentnog spiska, vise vrednosti, obavezno]
     */
    private const RELATIONS = [
        'status'            => ['Status', 'status', false, false],
        'location'          => ['Lokacija', 'location', false, true],
        'visibility_groups' => ['Visibility grupe', 'visibility_group', true, true],
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function relations(string $sourceKind): array
    {
        // Obe vrste CI-ja nose ista tri polja; razlika je samo u tome sto
        // asset uz njih trazi i asset_type, koji se bira iznad tabele.
        return self::RELATIONS;
    }

    /** Pravila upisa, redom od najbezbednijeg. */
    public const POLICIES = [
        'never'       => 'ne diraj',
        'create_only' => 'upiši samo pri kreiranju',
        'if_empty'    => 'popuni ako je prazno',
        'always'      => 'prepisuj uvek',
    ];

    /**
     * Izvorna polja, grupisana za padajuci spisak.
     *
     * @return array<string, array<string, string>> grupa => kolona => opis
     */
    public static function sourceGroups(string $sourceKind): array
    {
        $groups = [];

        foreach (self::SOURCES[$sourceKind] ?? [] as $column => [$group, $label]) {
            $groups[$group][$column] = $label;
        }

        return $groups;
    }

    /**
     * @return array<string, array{0: string, 1: string}> kolona => [opis, tip]
     */
    public static function sources(string $sourceKind): array
    {
        $flat = [];

        foreach (self::SOURCES[$sourceKind] ?? [] as $column => [, $label, $type]) {
            $flat[$column] = [$label, $type];
        }

        return $flat;
    }

    public static function sourceLabel(string $sourceKind, ?string $column): string
    {
        if ($column === null || $column === '') {
            return '';
        }

        return self::sources($sourceKind)[$column][0] ?? $column;
    }

    public static function sourceType(string $sourceKind, ?string $column): ?string
    {
        if ($column === null || $column === '') {
            return null;
        }

        return self::sources($sourceKind)[$column][1] ?? null;
    }

    /**
     * @return array<string, array{0: string, 1: string}> polje => [opis, tip]
     */
    public static function nativeFields(string $sourceKind): array
    {
        return self::NATIVE[$sourceKind] ?? [];
    }

    public static function entity(string $sourceKind): string
    {
        return self::ENTITY[$sourceKind] ?? 'asset';
    }
}
