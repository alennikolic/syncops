<?php
// src/Source/VCenter/Client/Soap/SoapHardwareClient.php
//
// Jedina svrha: hardverski podaci o ESXi hostovima, koje REST API ne daje —
// proizvodjac, model, serijski broj, CPU, memorija, ESXi build.
//
// Postupak (pet SOAP poziva ukupno, bez obzira na broj hostova):
//   1. RetrieveServiceContent  -> korenski folder, propertyCollector, verzija
//   2. Login                   -> kolacic sesije
//   3. CreateContainerView     -> pogled na sve HostSystem objekte
//   4. RetrievePropertiesEx    -> trazena polja za SVE hostove odjednom
//   5. DestroyView + Logout
//
// Zasto ContainerView a ne klasican TraversalSpec: isti rezultat sa deset puta
// manje XML-a. Klasican pristup trazi lanac specova kroz Datacenter ->
// hostFolder -> ComputeResource -> host, svaki sa svojim imenom i vezama.
//
// Podaci se citaju JEDNOM i cuvaju u memoriji, pa getHardware() po hostu ne
// pravi nov poziv.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use SensitiveParameter;
use SyncOps\Source\VCenter\VCenterException;

final class SoapHardwareClient
{
    /** Polja koja se traze za svaki host. */
    private const PROPERTIES = [
        'name',
        'hardware.systemInfo',
        'hardware.cpuInfo',
        'hardware.cpuPkg',
        'hardware.memorySize',
        'hardware.biosInfo',
        'config.product',
    ];

    /** Kljucevi pod kojima vCenter prijavljuje serijski broj, po prioritetu. */
    private const SERIAL_KEYS = [
        'SerialNumberTag',
        'ServiceTag',
        'EnclosureSerialNumberTag',
    ];

    private ?string $rootFolder = null;
    private ?string $propertyCollector = null;
    private ?string $sessionManager = null;
    private ?string $viewManager = null;
    private ?string $productVersion = null;

    /** @var array<string, array<string, mixed>>|null moref => hardverski podaci */
    private ?array $cache = null;

    public function __construct(
        private readonly SoapTransport $http,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {
    }

    public function callCount(): int
    {
        return $this->http->callCount();
    }

    public function productVersion(): ?string
    {
        return $this->productVersion;
    }

    // -----------------------------------------------------------------
    // Sesija
    // -----------------------------------------------------------------

    public function login(): void
    {
        if ($this->http->hasSession()) {
            return;
        }

        $this->retrieveServiceContent();

        $this->http->call('Login', sprintf(
            '<Login xmlns="urn:vim25">'
            . '<_this type="SessionManager">%s</_this>'
            . '<userName>%s</userName>'
            . '<password>%s</password>'
            . '</Login>',
            $this->escape($this->sessionManager ?? 'SessionManager'),
            $this->escape($this->username),
            $this->escape($this->password)
        ));
    }

    public function logout(): void
    {
        if (!$this->http->hasSession()) {
            return;
        }

        try {
            $this->http->call('Logout', sprintf(
                '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
                $this->escape($this->sessionManager ?? 'SessionManager')
            ));
        } catch (VCenterException) {
            // Neuspela odjava nije vredna prijavljivanja — sesija istekne sama.
        }

        $this->http->forgetSession();
    }

    // -----------------------------------------------------------------
    // Hardver
    // -----------------------------------------------------------------

    /**
     * Hardverski podaci jednog hosta, ili null ako ga vCenter ne prijavljuje
     * (najcesce zato sto je host odvojen, pa nema sta da javi).
     *
     * @return array<string, mixed>|null
     */
    public function getHardware(string $moref): ?array
    {
        if ($this->cache === null) {
            $this->cache = $this->fetchAll();
        }

        return $this->cache[$moref] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->fetchAll();
        }

        return $this->cache;
    }

    // -----------------------------------------------------------------

    private function retrieveServiceContent(): void
    {
        $xml = $this->http->call(
            'RetrieveServiceContent',
            '<RetrieveServiceContent xmlns="urn:vim25">'
            . '<_this type="ServiceInstance">ServiceInstance</_this>'
            . '</RetrieveServiceContent>'
        );

        $xpath = $this->xpath($xml);

        $this->rootFolder        = $this->text($xpath, '//*[local-name()="rootFolder"]');
        $this->propertyCollector = $this->text($xpath, '//*[local-name()="propertyCollector"]');
        $this->sessionManager    = $this->text($xpath, '//*[local-name()="sessionManager"]');
        $this->viewManager       = $this->text($xpath, '//*[local-name()="viewManager"]');
        $this->productVersion    = $this->text($xpath, '//*[local-name()="about"]/*[local-name()="version"]');

        // SOAPAction mora da nosi verziju API-ja: bez nje vCenter za novije
        // metode javlja da ne postoje.
        $apiVersion = $this->text($xpath, '//*[local-name()="about"]/*[local-name()="apiVersion"]');
        $this->http->setApiVersion($apiVersion);

        if ($this->rootFolder === null || $this->propertyCollector === null) {
            throw new VCenterException(
                'SOAP odgovor ne sadrzi rootFolder ni propertyCollector — '
                . 'krajnja tacka /sdk mozda nije vCenter.'
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchAll(): array
    {
        $this->login();

        $view = $this->createContainerView();

        try {
            $hosts = [];
            $token = null;

            do {
                $xml = $token === null
                    ? $this->retrieveProperties($view)
                    : $this->continueRetrieve($token);

                $xpath = $this->xpath($xml);

                foreach ($xpath->query('//*[local-name()="objects"]') as $object) {
                    [$moref, $hardware] = $this->parseHost($xpath, $object);

                    if ($moref === null) {
                        continue;
                    }

                    // Odvojen host nema sta da javi, pa vCenter za njega ne
                    // vrati nijedno hardversko polje. Takav se NE upisuje:
                    // inace bi dobio hardware_source = soap sa praznim
                    // kolonama, a to je upravo razlika koju ta kolona cuva —
                    // "nije prikupljeno" naspram "prikupljeno pa prazno".
                    if (array_filter($hardware, static fn ($v): bool => $v !== null) === []) {
                        continue;
                    }

                    $hosts[$moref] = $hardware;
                }

                // Veliki inventar stize u delovima; token kaze da ima jos.
                $token = $this->text($xpath, '//*[local-name()="returnval"]/*[local-name()="token"]');
            } while ($token !== null && $token !== '');

            return $hosts;
        } finally {
            $this->destroyView($view);
        }
    }

    private function createContainerView(): string
    {
        $xml = $this->http->call('CreateContainerView', sprintf(
            '<CreateContainerView xmlns="urn:vim25">'
            . '<_this type="ViewManager">%s</_this>'
            . '<container type="Folder">%s</container>'
            . '<type>HostSystem</type>'
            . '<recursive>true</recursive>'
            . '</CreateContainerView>',
            $this->escape($this->viewManager ?? 'ViewManager'),
            $this->escape((string) $this->rootFolder)
        ));

        $view = $this->text($this->xpath($xml), '//*[local-name()="returnval"]');

        if ($view === null) {
            throw new VCenterException('vCenter nije vratio pogled na hostove.');
        }

        return $view;
    }

    private function retrieveProperties(string $view): string
    {
        $pathSet = '';

        foreach (self::PROPERTIES as $property) {
            $pathSet .= '<pathSet>' . $property . '</pathSet>';
        }

        return $this->http->call('RetrievePropertiesEx', sprintf(
            '<RetrievePropertiesEx xmlns="urn:vim25">'
            . '<_this type="PropertyCollector">%s</_this>'
            . '<specSet>'
            . '<propSet><type>HostSystem</type>%s</propSet>'
            . '<objectSet>'
            . '<obj type="ContainerView">%s</obj>'
            . '<skip>true</skip>'
            . '<selectSet xsi:type="TraversalSpec">'
            . '<name>viewToHost</name>'
            . '<type>ContainerView</type>'
            . '<path>view</path>'
            . '<skip>false</skip>'
            . '</selectSet>'
            . '</objectSet>'
            . '</specSet>'
            . '<options />'
            . '</RetrievePropertiesEx>',
            $this->escape((string) $this->propertyCollector),
            $pathSet,
            $this->escape($view)
        ));
    }

    private function continueRetrieve(string $token): string
    {
        return $this->http->call('ContinueRetrievePropertiesEx', sprintf(
            '<ContinueRetrievePropertiesEx xmlns="urn:vim25">'
            . '<_this type="PropertyCollector">%s</_this>'
            . '<token>%s</token>'
            . '</ContinueRetrievePropertiesEx>',
            $this->escape((string) $this->propertyCollector),
            $this->escape($token)
        ));
    }

    private function destroyView(string $view): void
    {
        try {
            $this->http->call('DestroyView', sprintf(
                '<DestroyView xmlns="urn:vim25"><_this type="ContainerView">%s</_this></DestroyView>',
                $this->escape($view)
            ));
        } catch (VCenterException) {
            // Pogled istekne sam sa sesijom; neuspeh ovde nije vredan buke.
        }
    }

    // -----------------------------------------------------------------
    // Citanje odgovora
    // -----------------------------------------------------------------

    /**
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function parseHost(DOMXPath $xpath, DOMNode $object): array
    {
        $moref = $this->text($xpath, './*[local-name()="obj"]', $object);

        if ($moref === null) {
            return [null, []];
        }

        $props = [];

        foreach ($xpath->query('./*[local-name()="propSet"]', $object) as $propSet) {
            $name = $this->text($xpath, './*[local-name()="name"]', $propSet);

            if ($name !== null) {
                $props[$name] = $propSet;
            }
        }

        $systemInfo = $props['hardware.systemInfo'] ?? null;
        $cpuInfo    = $props['hardware.cpuInfo'] ?? null;
        $biosInfo   = $props['hardware.biosInfo'] ?? null;
        $product    = $props['config.product'] ?? null;

        $hardware = [
            'vendor'        => $this->val($xpath, $systemInfo, 'vendor'),
            'model'         => $this->val($xpath, $systemInfo, 'model'),
            'hardware_uuid' => $this->val($xpath, $systemInfo, 'uuid'),
            'serial_number' => $this->serialNumber($xpath, $systemInfo),
            'asset_tag'     => $this->identifier($xpath, $systemInfo, ['AssetTag']),

            'cpu_model'   => $this->cpuModel($xpath, $props['hardware.cpuPkg'] ?? null),
            'cpu_sockets' => $this->intVal($xpath, $cpuInfo, 'numCpuPackages'),
            'cpu_cores'   => $this->intVal($xpath, $cpuInfo, 'numCpuCores'),
            'cpu_threads' => $this->intVal($xpath, $cpuInfo, 'numCpuThreads'),
            'cpu_mhz'     => $this->cpuMhz($xpath, $cpuInfo),

            'memory_bytes' => $this->intVal($xpath, $props['hardware.memorySize'] ?? null, null),

            'esxi_version' => $this->val($xpath, $product, 'version'),
            'esxi_build'   => $this->val($xpath, $product, 'build'),
            'bios_version' => $this->val($xpath, $biosInfo, 'biosVersion'),
        ];

        return [$moref, $hardware];
    }

    /**
     * Serijski broj: prvo polje serialNumber (postoji od novijih verzija), pa
     * otherIdentifyingInfo, gde ga proizvodjaci prijavljuju pod raznim
     * kljucevima.
     */
    private function serialNumber(DOMXPath $xpath, ?DOMNode $systemInfo): ?string
    {
        $direct = $this->val($xpath, $systemInfo, 'serialNumber');

        if ($direct !== null) {
            return $direct;
        }

        return $this->identifier($xpath, $systemInfo, self::SERIAL_KEYS);
    }

    /**
     * Citanje iz otherIdentifyingInfo: niz parova
     * {identifierValue, identifierType: {key: "ServiceTag"}}.
     *
     * @param list<string> $keys
     */
    private function identifier(DOMXPath $xpath, ?DOMNode $systemInfo, array $keys): ?string
    {
        if ($systemInfo === null) {
            return null;
        }

        foreach ($keys as $key) {
            $nodes = $xpath->query(
                './/*[local-name()="otherIdentifyingInfo"]'
                . '[.//*[local-name()="key"]="' . $key . '"]'
                . '/*[local-name()="identifierValue"]',
                $systemInfo
            );

            foreach ($nodes as $node) {
                $value = trim($node->textContent);

                // Proizvodjaci znaju da upisu placeholder umesto broja.
                if ($value !== '' && !in_array(strtolower($value), ['unknown', 'none', 'n/a', 'to be filled by o.e.m.'], true)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function cpuModel(DOMXPath $xpath, ?DOMNode $cpuPkg): ?string
    {
        if ($cpuPkg === null) {
            return null;
        }

        $nodes = $xpath->query('.//*[local-name()="description"]', $cpuPkg);

        foreach ($nodes as $node) {
            $value = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** cpuInfo.hz je u hercima; u bazi se cuva u megahercima. */
    private function cpuMhz(DOMXPath $xpath, ?DOMNode $cpuInfo): ?int
    {
        $hz = $this->intVal($xpath, $cpuInfo, 'hz');

        return $hz === null ? null : (int) round($hz / 1_000_000);
    }

    // -----------------------------------------------------------------
    // Sitni pomocnici
    // -----------------------------------------------------------------

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();

        // Greske u XML-u se hvataju ovde, ne ispisuju kao PHP upozorenja.
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new VCenterException('SOAP odgovor nije ispravan XML.');
        }

        return new DOMXPath($document);
    }

    private function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)?->textContent ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Vrednost jednog polja unutar <val> elementa propSet-a. Kada je $field
     * null, uzima se sam sadrzaj <val> (za proste vrednosti kao memorySize).
     */
    private function val(DOMXPath $xpath, ?DOMNode $propSet, ?string $field): ?string
    {
        if ($propSet === null) {
            return null;
        }

        $query = $field === null
            ? './*[local-name()="val"]'
            : './*[local-name()="val"]/*[local-name()="' . $field . '"]';

        return $this->text($xpath, $query, $propSet);
    }

    private function intVal(DOMXPath $xpath, ?DOMNode $propSet, ?string $field): ?int
    {
        $value = $this->val($xpath, $propSet, $field);

        return ($value === null || !is_numeric($value)) ? null : (int) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
