<?php
// src/Source/VCenter/Client/Soap/HostHardwareParser.php
//
// Citanje hardverskih polja jednog HostSystem objekta iz vim25 odgovora.
//
// Izdvojeno u trait jer ista logika treba na dva mesta: SoapHardwareClient-u
// (koji dopunjuje REST) i Vim25Client-u (koji radi sve sam). Nasledjivanje ne
// bi odgovaralo — oni nisu jedan drugom vrsta, samo dele nacin citanja.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

use DOMNode;
use DOMXPath;

trait HostHardwareParser
{
    /** Polja koja se traze za svaki host. */
    private const HARDWARE_PROPERTIES = [
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

    /** Vrednosti koje proizvodjaci upisu umesto stvarnog broja. */
    private const PLACEHOLDERS = ['unknown', 'none', 'n/a', 'to be filled by o.e.m.'];

    /**
     * @param array<string, DOMNode> $props propSet-ovi po imenu
     * @return array<string, mixed>
     */
    private function parseHostHardware(DOMXPath $xpath, array $props): array
    {
        $systemInfo = $props['hardware.systemInfo'] ?? null;
        $cpuInfo    = $props['hardware.cpuInfo'] ?? null;
        $biosInfo   = $props['hardware.biosInfo'] ?? null;
        $product    = $props['config.product'] ?? null;

        return [
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
    }

    /**
     * Odvojen host nema sta da javi, pa vCenter za njega ne vrati nijedno
     * hardversko polje. Takav se NE upisuje: inace bi dobio
     * hardware_source = soap sa praznim kolonama, a to je upravo razlika
     * koju ta kolona cuva — "nije prikupljeno" naspram "prikupljeno pa prazno".
     *
     * @param array<string, mixed> $hardware
     */
    private function hasAnyHardware(array $hardware): bool
    {
        return array_filter($hardware, static fn ($v): bool => $v !== null) !== [];
    }

    /**
     * Serijski broj: prvo polje serialNumber (postoji od novijih verzija), pa
     * otherIdentifyingInfo, gde ga proizvodjaci prijavljuju pod raznim
     * kljucevima.
     */
    private function serialNumber(DOMXPath $xpath, ?DOMNode $systemInfo): ?string
    {
        $direct = $this->val($xpath, $systemInfo, 'serialNumber');

        if ($direct !== null && !$this->isPlaceholder($direct)) {
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

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = trim($node->textContent);

                if ($value !== '' && !$this->isPlaceholder($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function isPlaceholder(string $value): bool
    {
        return in_array(strtolower($value), self::PLACEHOLDERS, true);
    }

    private function cpuModel(DOMXPath $xpath, ?DOMNode $cpuPkg): ?string
    {
        if ($cpuPkg === null) {
            return null;
        }

        $nodes = $xpath->query('.//*[local-name()="description"]', $cpuPkg);

        if ($nodes === false) {
            return null;
        }

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
}
