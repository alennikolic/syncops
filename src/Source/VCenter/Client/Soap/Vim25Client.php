<?php
// src/Source/VCenter/Client/Soap/Vim25Client.php
//
// Pun vCenter klijent preko vSphere Web Services API-ja (vim25, /sdk).
// Koristi se kada je dijalekt integracije "soap" — tada REST ne ucestvuje.
//
// Zasto postoji, kad REST radi:
//   - REST uopste ne vraca VM templejte. Za CMDB to znaci lazan "nestanak"
//     svaki put kad neko konvertuje masinu u templejt.
//   - REST nema hardver hostova, pa je i dosad morao da se dopunjuje SOAP-om.
//   - REST trazi poziv po masini za instance_uuid i jos jedan za podatke iz
//     gosta. SOAP sve to vrati u JEDNOM pozivu za ceo inventar.
//   - REST /api postoji tek od 7.0U2 i ima ogranicenje od 4000 objekata.
//
// Racunica poziva za inventar od 153 masine i 5 hostova:
//   REST: oko 320      SOAP: oko 12
//
// Nacelo: KLIJENT GOVORI REST-OV JEZIK. Interfejs obecava kolektorima uvek
// isti oblik podataka, pa se ovde vim25 vrednosti prevode u ono sto REST
// vraca — poweredOn -> POWERED_ON, vmx-15 -> VMX_15. Bez toga bi prelazak sa
// REST-a na SOAP u journalu izgledao kao da se promenio ceo inventar.
//
// Jedan izuzetak je guestId: vim25 ga daje kao "windows2019srv_64Guest", a
// REST kao "WINDOWS_SERVER_2019". Prevod bi trazio tabelu od dvestotinak
// vrednosti koja bi zastarevala sa svakom novom verzijom vSphere-a, pa se
// vrednost prenosi onakva kakva jeste. Posledica je jednokratna: pri prvom
// prikupljanju posle prelaska, guest_os se promeni za svaku masinu i to
// upise u journal.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

use DOMNode;
use DOMXPath;
use SyncOps\Integration\Integration;
use SyncOps\Source\VCenter\Client\ClientInterface;

final class Vim25Client extends AbstractSoapClient implements ClientInterface
{
    use HostHardwareParser;

    /** Polja koja se traze za svaku masinu. */
    private const VM_PROPERTIES = [
        'name',
        'parent',
        'config.instanceUuid',
        'config.uuid',
        'config.template',
        'config.version',
        'config.guestId',
        'config.annotation',
        'config.hardware.numCPU',
        'config.hardware.numCoresPerSocket',
        'config.hardware.memoryMB',
        'config.hardware.device',
        'runtime.powerState',
        'runtime.host',
        'guest.hostName',
        'guest.ipAddress',
        'guest.guestState',
        'guest.guestFullName',
        'guest.guestFamily',
    ];

    /** Polja koja se traze za svaki host: inventar + hardver, jednim pozivom. */
    private const HOST_INVENTORY_PROPERTIES = [
        'name',
        'parent',
        'runtime.connectionState',
        'runtime.powerState',
    ];

    private const CLUSTER_PROPERTIES = [
        'name',
        'configuration.dasConfig.enabled',
        'configuration.drsConfig.enabled',
    ];

    /**
     * Polja za svaki datastore.
     *
     * info se trazi ceo iako se zadrzava samo deo: vSphere ume i ugnezdjene
     * putanje (info.vmfs.uuid), ali one vrede samo za VMFS — kod NFS-a i
     * vSAN-a bi dosle kao nepostojece polje. Jedan trazeni blok radi za sve
     * tipove, a secenje se radi ovde.
     */
    private const DATASTORE_PROPERTIES = [
        'name',
        'summary.type',
        'summary.capacity',
        'summary.freeSpace',
        'summary.uncommitted',
        'summary.accessible',
        'summary.multipleHostAccess',
        'summary.maintenanceMode',
        'summary.url',
        'overallStatus',
        'host',
        'info',
    ];

    /** vim25 -> oblik koji vracaju REST dijalekti. */
    private const VM_POWER = [
        'poweredOn'  => 'POWERED_ON',
        'poweredOff' => 'POWERED_OFF',
        'suspended'  => 'SUSPENDED',
    ];

    private const HOST_POWER = [
        'poweredOn'  => 'POWERED_ON',
        'poweredOff' => 'POWERED_OFF',
        'standBy'    => 'STANDBY',
    ];

    private const HOST_CONNECTION = [
        'connected'     => 'CONNECTED',
        'disconnected'  => 'DISCONNECTED',
        'notResponding' => 'NOT_RESPONDING',
    ];

    private const GUEST_FAMILY = [
        'windowsGuest'     => 'WINDOWS',
        'linuxGuest'       => 'LINUX',
        'netwareGuest'     => 'NETWARE',
        'solarisGuest'     => 'SOLARIS',
        'darwinGuestFamily' => 'DARWIN',
        'otherGuestFamily' => 'OTHER',
    ];

    /** @var array<string, array<string, mixed>>|null moref => masina */
    private ?array $vms = null;

    /** @var array<string, array<string, array<string, mixed>>> kljuc kontejnera => moref => host */
    private array $hosts = [];

    // -----------------------------------------------------------------
    // Identitet
    // -----------------------------------------------------------------

    public function flavor(): string
    {
        return Integration::FLAVOR_SOAP;
    }

    /**
     * Verzija se saznaje iz ServiceContent-a, koji stize uz prijavu — za
     * razliku od REST-a, ovde ne treba poseban poziv ni posebno pravo.
     */
    public function productVersion(): ?string
    {
        if ($this->productVersion === null) {
            $this->login();
        }

        return $this->productVersion;
    }

    // -----------------------------------------------------------------
    // Datacentri i klasteri
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function listDatacenters(): array
    {
        $rows = $this->eachObject(
            'Datacenter',
            ['name'],
            fn (DOMXPath $xpath, DOMNode $object, string $moref): array => [
                'datacenter' => $moref,
                'name'       => (string) ($this->val($xpath, $this->propSets($xpath, $object)['name'] ?? null, null) ?? ''),
            ]
        );

        return array_values($rows);
    }

    /**
     * @param list<string> $datacenterIds
     * @return list<array<string, mixed>>
     */
    public function listClusters(array $datacenterIds = []): array
    {
        $containers = $datacenterIds === [] ? [null] : $datacenterIds;
        $clusters   = [];

        foreach ($containers as $container) {
            $rows = $this->eachObject(
                'ClusterComputeResource',
                self::CLUSTER_PROPERTIES,
                function (DOMXPath $xpath, DOMNode $object, string $moref): array {
                    $props = $this->propSets($xpath, $object);

                    return [
                        'cluster'     => $moref,
                        'name'        => (string) ($this->val($xpath, $props['name'] ?? null, null) ?? ''),
                        'ha_enabled'  => $this->boolVal($xpath, $props['configuration.dasConfig.enabled'] ?? null, null),
                        'drs_enabled' => $this->boolVal($xpath, $props['configuration.drsConfig.enabled'] ?? null, null),
                    ];
                },
                $container,
                $container === null ? 'Folder' : 'Datacenter'
            );

            $clusters += $rows;
        }

        return array_values($clusters);
    }

    // -----------------------------------------------------------------
    // Hostovi
    // -----------------------------------------------------------------

    /**
     * @param list<string> $clusterIds
     * @param list<string> $datacenterIds
     * @return list<array<string, mixed>>
     */
    public function listHosts(array $clusterIds = [], array $datacenterIds = []): array
    {
        // Filtriranje po klasteru se radi iz vec procitanog inventara: host
        // nosi moref svog klastera u polju parent, pa nov poziv nije potreban.
        if ($clusterIds !== []) {
            $wanted = array_flip($clusterIds);
            $rows   = [];

            foreach ($this->hostsIn(null, 'Folder') as $host) {
                if (isset($wanted[(string) $host['_parent']])) {
                    $rows[] = $this->hostRow($host);
                }
            }

            return $rows;
        }

        // Po datacentru se pogled suzava na strani vCentera — iz inventara se
        // datacentar ne vidi direktno, jer host pokazuje na klaster, a ne dalje.
        if ($datacenterIds !== []) {
            $rows = [];

            foreach ($datacenterIds as $datacenter) {
                foreach ($this->hostsIn($datacenter, 'Datacenter') as $host) {
                    $rows[] = $this->hostRow($host);
                }
            }

            return $rows;
        }

        return array_map($this->hostRow(...), array_values($this->hostsIn(null, 'Folder')));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHostHardware(string $hostId): ?array
    {
        $host = $this->hostsIn(null, 'Folder')[$hostId] ?? null;

        if ($host === null) {
            return null;
        }

        /** @var array<string, mixed> $hardware */
        $hardware = $host['_hardware'];

        return $this->hasAnyHardware($hardware) ? $hardware : null;
    }

    // -----------------------------------------------------------------
    // Masine
    // -----------------------------------------------------------------

    /**
     * @param list<string> $clusterIds
     * @param list<string> $datacenterIds
     * @return list<array<string, mixed>>
     */
    public function listVms(array $clusterIds = [], array $datacenterIds = []): array
    {
        $all = $this->allVms();

        if ($clusterIds === []) {
            return array_values(array_map($this->vmSummary(...), $all));
        }

        // Klaster masine se izvodi preko hosta na kom radi; particionisanje po
        // klasteru SOAP-u ionako ne treba, ali interfejs ga obecava.
        $wanted   = array_flip($clusterIds);
        $hostToCl = [];

        foreach ($this->hostsIn(null, 'Folder') as $moref => $host) {
            $hostToCl[$moref] = (string) $host['_parent'];
        }

        $rows = [];

        foreach ($all as $vm) {
            $host = $vm['_host'];

            if ($host !== null && isset($wanted[$hostToCl[$host] ?? ''])) {
                $rows[] = $this->vmSummary($vm);
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVmsOnHost(string $hostId): array
    {
        $rows = [];

        foreach ($this->allVms() as $vm) {
            if ($vm['_host'] === $hostId) {
                $rows[] = $this->vmSummary($vm);
            }
        }

        return $rows;
    }

    /**
     * Detalji masine. Ne pravi poziv — sve je procitano u jednom prolazu.
     *
     * @return array<string, mixed>
     */
    public function getVm(string $vmId): array
    {
        $vm = $this->allVms()[$vmId] ?? null;

        if ($vm === null) {
            return [];
        }

        return $vm['detail'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVmGuestIdentity(string $vmId): ?array
    {
        $vm = $this->allVms()[$vmId] ?? null;

        if ($vm === null) {
            return null;
        }

        $guest = $vm['guest'];

        // Isto pravilo kao kod REST-a: bez imena i bez adrese nema sta da se
        // prijavi, pa null znaci "Tools ne rade".
        return ($guest['host_name'] === null && $guest['ip_address'] === null) ? null : $guest;
    }

    // -----------------------------------------------------------------
    // Datastore-ovi
    // -----------------------------------------------------------------

    /**
     * Svi datastore-ovi jednim pozivom.
     *
     * Iz info bloka se zadrzava samo ono sto se ne ponavlja drugde: uuid i
     * verzija VMFS-a, pa spisak ekstenata u payload. Ostatak (maxFileSize,
     * maxVirtualDiskCapacity, maxMemoryFileSize, maxPhysicalRDMFileSize,
     * maxVirtualRDMFileSize, timestamp) su ogranicenja verzije VMFS-a, ista
     * za sve datastore-ove iste verzije, plus duplikati name, url i
     * freeSpace koji vec stoje u summary-ju.
     *
     * @return list<array<string, mixed>>
     */
    public function listDatastores(): array
    {
        $rows = $this->eachObject(
            'Datastore',
            self::DATASTORE_PROPERTIES,
            function (DOMXPath $xpath, DOMNode $object, string $moref): array {
                $props = $this->propSets($xpath, $object);

                // Polje koje nije stiglo se NE upisuje: maper tako razlikuje
                // "vCenter nije rekao" od nule.
                $row = ['datastore' => $moref];

                $uzmi = function (string $kljuc, string $polje) use (&$row, $xpath, $props): void {
                    if (!isset($props[$polje])) {
                        return;
                    }

                    $value = $this->val($xpath, $props[$polje], null);

                    if ($value !== null) {
                        $row[$kljuc] = $value;
                    }
                };

                $uzmi('name', 'name');
                $uzmi('type', 'summary.type');
                $uzmi('capacity', 'summary.capacity');
                $uzmi('free_space', 'summary.freeSpace');
                $uzmi('uncommitted', 'summary.uncommitted');
                $uzmi('accessible', 'summary.accessible');
                $uzmi('multiple_host_access', 'summary.multipleHostAccess');
                $uzmi('maintenance_mode', 'summary.maintenanceMode');
                $uzmi('url', 'summary.url');
                $uzmi('overall_status', 'overallStatus');

                if (isset($props['host'])) {
                    $row['hosts'] = $this->mountedHosts($xpath, $props['host']);
                }

                if (isset($props['info'])) {
                    $row += $this->datastoreInfo($xpath, $props['info']);
                }

                return $row;
            }
        );

        return array_values($rows);
    }

    /**
     * Moref-ovi hostova koji montiraju datastore.
     *
     * Jedan LUN ume da vide svi hostovi klastera, pa ovo nije jedan na jedan.
     * Cuva se spisak, a broj se racuna iz njega.
     *
     * @return list<string>
     */
    private function mountedHosts(DOMXPath $xpath, DOMNode $propSet): array
    {
        $hosts = [];

        foreach ($xpath->query('./*[local-name()="val"]/*', $propSet) ?: [] as $mount) {
            $host = $this->text($xpath, './*[local-name()="key"]', $mount);

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Ono malo iz info bloka sto vredi zadrzati.
     *
     * Oblik se razlikuje po tipu: VmfsDatastoreInfo nosi <vmfs>, a
     * NasDatastoreInfo <nas> sa udaljenim hostom i putanjom. Sto ne postoji,
     * to se i ne upisuje.
     *
     * @return array<string, mixed>
     */
    private function datastoreInfo(DOMXPath $xpath, DOMNode $propSet): array
    {
        $info = [];

        $map = [
            'vmfs_uuid'    => './*[local-name()="val"]/*[local-name()="vmfs"]/*[local-name()="uuid"]',
            'vmfs_version' => './*[local-name()="val"]/*[local-name()="vmfs"]/*[local-name()="version"]',
            'nas_host'     => './*[local-name()="val"]/*[local-name()="nas"]/*[local-name()="remoteHost"]',
            'nas_path'     => './*[local-name()="val"]/*[local-name()="nas"]/*[local-name()="remotePath"]',
        ];

        foreach ($map as $kljuc => $putanja) {
            $value = $this->text($xpath, $putanja, $propSet);

            if ($value !== null) {
                $info[$kljuc] = $value;
            }
        }

        // Ekstenti: na kojim diskovima datastore stvarno lezi. Ide samo u
        // payload, jer ih ume biti vise po datastore-u.
        $extents = [];

        $query = './*[local-name()="val"]/*[local-name()="vmfs"]/*[local-name()="extent"]'
            . '/*[local-name()="diskName"]';

        foreach ($xpath->query($query, $propSet) ?: [] as $extent) {
            $disk = trim($extent->textContent);

            if ($disk !== '') {
                $extents[] = $disk;
            }
        }

        if ($extents !== []) {
            $info['extents'] = $extents;
        }

        return $info;
    }

    // -----------------------------------------------------------------
    // Licence
    // -----------------------------------------------------------------

    /**
     * Sve licence jednim pozivom.
     *
     * LicenseManager je singlton i ne stoji u stablu inventara, pa se cita
     * direktno umesto kroz ContainerView.
     *
     * Odbacuju se dve vrste osobina, obe zato sto nadimaju payload bez
     * vrednosti za evidenciju:
     *   feature     — pedesetak zapisa po licenci, spisak moguconosti edicije
     *   LicenseInfo — cela ugnezdjena kopija licence, po jedna za svaki
     *                 FileVersion; na stvarnom odgovoru to je bilo preko pet
     *                 hiljada redova XML-a za devet licenci
     *
     * @return array{licenses: list<array<string, mixed>>, missingPrivilege: ?string}
     */
    public function listLicenses(): array
    {
        // licenseManager stize uz ServiceContent, koji se cita pri prijavi.
        $this->login();

        if ($this->licenseManager === null) {
            return ['licenses' => [], 'missingPrivilege' => null];
        }

        $xpath   = $this->retrieveObject('LicenseManager', $this->licenseManager, ['licenses']);
        $missing = $this->missingPrivilege($xpath, 'licenses');

        if ($missing !== null) {
            return ['licenses' => [], 'missingPrivilege' => $missing];
        }

        // Putanja je vezana za <val> unutar <propSet>, da se ne pokupe
        // ugnezdjene kopije licence iz properties — one stoje pod <value>.
        $nodes = $xpath->query(
            '//*[local-name()="propSet"]/*[local-name()="val"]'
            . '/*[local-name()="LicenseManagerLicenseInfo"]'
        );

        $licenses = [];

        foreach ($nodes ?: [] as $node) {
            $licenses[] = [
                'key'         => $this->text($xpath, './*[local-name()="licenseKey"]', $node) ?? '',
                'name'        => $this->text($xpath, './*[local-name()="name"]', $node) ?? '',
                'edition_key' => $this->text($xpath, './*[local-name()="editionKey"]', $node),
                'total'       => $this->text($xpath, './*[local-name()="total"]', $node),
                'used'        => $this->text($xpath, './*[local-name()="used"]', $node),
                'cost_unit'   => $this->text($xpath, './*[local-name()="costUnit"]', $node),
                'properties'  => $this->licenseProperties($xpath, $node),
            ];
        }

        return ['licenses' => $licenses, 'missingPrivilege' => null];
    }

    /**
     * Skalarne osobine jedne licence: ProductName, ProductVersion,
     * expirationDate i slicne.
     *
     * Cita se SAMO prvi nivo (./properties), pa ugnezdjene kopije licence ne
     * mogu da se umesaju. Osobina koja nije prost tekst se preskace.
     *
     * @return array<string, string>
     */
    private function licenseProperties(DOMXPath $xpath, DOMNode $license): array
    {
        $skip = ['feature' => true, 'LicenseInfo' => true, 'Localized' => true];

        $properties = [];

        foreach ($xpath->query('./*[local-name()="properties"]', $license) ?: [] as $property) {
            $key = $this->text($xpath, './*[local-name()="key"]', $property);

            if ($key === null || isset($skip[$key])) {
                continue;
            }

            $value = $xpath->query('./*[local-name()="value"]', $property);

            if ($value === false || $value->length === 0) {
                continue;
            }

            $node = $value->item(0);

            // Slozena vrednost (KeyValue, niz) ima decu; takvu ne cuvamo, jer
            // bi se u koloni nasao slepljen tekst bez znacenja.
            if ($node === null || $this->hasElementChildren($node)) {
                continue;
            }

            $text = trim($node->textContent);

            if ($text !== '') {
                $properties[$key] = $text;
            }
        }

        return $properties;
    }

    /** Da li cvor sadrzi druge elemente, a ne samo tekst. */
    private function hasElementChildren(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Citanje inventara
    // -----------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allVms(): array
    {
        if ($this->vms !== null) {
            return $this->vms;
        }

        /** @var array<string, array<string, mixed>> $vms */
        $vms = $this->eachObject(
            'VirtualMachine',
            self::VM_PROPERTIES,
            $this->parseVm(...)
        );

        $this->vms = $vms;

        return $vms;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hostsIn(?string $container, string $containerType): array
    {
        $key = $containerType . ':' . ($container ?? '');

        if (!isset($this->hosts[$key])) {
            /** @var array<string, array<string, mixed>> $hosts */
            $hosts = $this->eachObject(
                'HostSystem',
                [...self::HOST_INVENTORY_PROPERTIES, ...self::HARDWARE_PROPERTIES],
                $this->parseHost(...),
                $container,
                $containerType
            );

            $this->hosts[$key] = $hosts;
        }

        return $this->hosts[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseHost(DOMXPath $xpath, DOMNode $object, string $moref): array
    {
        $props = $this->propSets($xpath, $object);

        return [
            'moref'      => $moref,
            'name'       => (string) ($this->val($xpath, $props['name'] ?? null, null) ?? ''),
            '_parent'    => $this->val($xpath, $props['parent'] ?? null, null),
            'connection' => self::HOST_CONNECTION[
                (string) $this->val($xpath, $props['runtime.connectionState'] ?? null, null)
            ] ?? null,
            'power'      => self::HOST_POWER[
                (string) $this->val($xpath, $props['runtime.powerState'] ?? null, null)
            ] ?? null,
            '_hardware'  => $this->parseHostHardware($xpath, $props),
        ];
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    private function hostRow(array $host): array
    {
        return [
            'host'             => $host['moref'],
            'name'             => $host['name'],
            'connection_state' => $host['connection'],
            'power_state'      => $host['power'],
        ];
    }

    /**
     * Jedna masina: sazetak za liste, detalji za maper i blok iz gosta.
     *
     * @return array<string, mixed>
     */
    private function parseVm(DOMXPath $xpath, DOMNode $object, string $moref): array
    {
        $props = $this->propSets($xpath, $object);

        $name  = (string) ($this->val($xpath, $props['name'] ?? null, null) ?? '');
        $power = self::VM_POWER[
            (string) $this->val($xpath, $props['runtime.powerState'] ?? null, null)
        ] ?? null;

        [$disks, $nics] = $this->devices($xpath, $props['config.hardware.device'] ?? null);

        $detail = [
            'name'        => $name,
            'power_state' => $power,
            'guest_OS'    => $this->val($xpath, $props['config.guestId'] ?? null, null),
            'notes'       => $this->val($xpath, $props['config.annotation'] ?? null, null),
            'is_template' => $this->boolVal($xpath, $props['config.template'] ?? null, null) === true,

            'identity' => [
                'name'          => $name,
                'instance_uuid' => $this->val($xpath, $props['config.instanceUuid'] ?? null, null),
                'bios_uuid'     => $this->val($xpath, $props['config.uuid'] ?? null, null),
            ],

            'cpu' => [
                'count'            => $this->intVal($xpath, $props['config.hardware.numCPU'] ?? null, null),
                'cores_per_socket' => $this->intVal($xpath, $props['config.hardware.numCoresPerSocket'] ?? null, null),
            ],

            'memory' => [
                'size_MiB' => $this->intVal($xpath, $props['config.hardware.memoryMB'] ?? null, null),
            ],

            'hardware' => [
                'version' => $this->hardwareVersion($this->val($xpath, $props['config.version'] ?? null, null)),
            ],

            'disks' => $disks,
            'nics'  => $nics,
        ];

        $family = $this->val($xpath, $props['guest.guestFamily'] ?? null, null);

        return [
            'moref'  => $moref,
            '_host'  => $this->val($xpath, $props['runtime.host'] ?? null, null),
            'detail' => $detail,
            'guest'  => [
                'host_name'  => $this->val($xpath, $props['guest.hostName'] ?? null, null),
                'ip_address' => $this->val($xpath, $props['guest.ipAddress'] ?? null, null),
                'full_name'  => $this->val($xpath, $props['guest.guestFullName'] ?? null, null),
                'family'     => $family === null ? null : (self::GUEST_FAMILY[$family] ?? strtoupper($family)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $vm
     * @return array<string, mixed>
     */
    private function vmSummary(array $vm): array
    {
        /** @var array<string, mixed> $detail */
        $detail = $vm['detail'];

        return [
            'vm'              => $vm['moref'],
            'name'            => $detail['name'],
            'power_state'     => $detail['power_state'],
            'cpu_count'       => $detail['cpu']['count'],
            'memory_size_MiB' => $detail['memory']['size_MiB'],
        ];
    }

    /**
     * Diskovi i mrezne kartice iz liste uredjaja.
     *
     * Prepoznaju se po poljima a ne po xsi:type: tipova kartica ima desetak
     * (VirtualVmxnet3, VirtualE1000, VirtualPCNet32...) i lista raste sa
     * svakom verzijom. Svaka mrezna kartica ima macAddress, a svaki disk
     * kapacitet — to se ne menja.
     *
     * @return array{0: list<array<string, int>>, 1: list<array<string, string>>}
     */
    private function devices(DOMXPath $xpath, ?DOMNode $propSet): array
    {
        $disks = [];
        $nics  = [];

        if ($propSet === null) {
            return [$disks, $nics];
        }

        $nodes = $xpath->query('./*[local-name()="val"]/*', $propSet);

        if ($nodes === false) {
            return [$disks, $nics];
        }

        foreach ($nodes as $device) {
            $bytes = $this->text($xpath, './*[local-name()="capacityInBytes"]', $device);
            $kb    = $this->text($xpath, './*[local-name()="capacityInKB"]', $device);

            if ($bytes !== null || $kb !== null) {
                $disks[] = ['capacity' => $bytes !== null ? (int) $bytes : (int) $kb * 1024];

                continue;
            }

            $mac = $this->text($xpath, './*[local-name()="macAddress"]', $device);

            if ($mac !== null) {
                $nics[] = ['mac_address' => $mac];
            }
        }

        return [$disks, $nics];
    }

    /** vim25 javlja "vmx-15", REST "VMX_15". U bazi je dosad stajao REST-ov oblik. */
    private function hardwareVersion(?string $value): ?string
    {
        return $value === null ? null : strtoupper(str_replace('-', '_', $value));
    }
}
