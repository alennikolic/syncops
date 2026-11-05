<?php
// src/Source/VCenter/Mapper/HostMapper.php
//
// Odgovor API-ja -> HostDto.
//
// Sta /vcenter/host daje: host, name, connection_state, power_state. Nista vise.
// power_state IZOSTAJE kada je host NOT_RESPONDING (struktura odgovora ima
// union constraint), pa je odsustvo polja ocekivano a ne greska.
//
// Hardverski podaci (proizvodjac, model, serijski broj, CPU, RAM, ESXi build)
// se ovde ne popunjavaju jer ih REST nema. Metoda withHardware() postoji da ih
// SOAP klijent kasnije prikaci bez prepravljanja kolektora.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\HostDto;

final class HostMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, ?string $clusterMoref, ?string $datacenterMoref): HostDto
    {
        $payload = $row + [
            '_placement' => ['cluster' => $clusterMoref, 'datacenter' => $datacenterMoref],
        ];

        return new HostDto(
            moref:           (string) ($row['host'] ?? ''),
            name:            (string) ($row['name'] ?? ''),
            connectionState: isset($row['connection_state']) ? (string) $row['connection_state'] : null,
            powerState:      isset($row['power_state']) ? (string) $row['power_state'] : null,
            clusterMoref:    $clusterMoref,
            datacenterMoref: $datacenterMoref,
            rawPayload:      (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Dopuni hardverske podatke kada ih bude bilo odakle uzeti.
     *
     * Hardver se dopisuje i u rawPayload, pod kljucem _hardware. Bez toga bi
     * u raw_object stajao samo REST odgovor, pa bi se izgubila jedina svrha
     * te tabele: da se polje koje maper danas ne cita kasnije izvuce iz nje,
     * bez ponovnog skeniranja infrastrukture.
     *
     * Podvlaka u imenu kljuca znaci isto sto i kod _placement: polje nije
     * stiglo iz odgovora nego ga je SyncOPS dodao. _source kaze kojim
     * protokolom, jer REST i SOAP ne daju iste podatke.
     *
     * @param array<string, mixed> $hw
     */
    public function withHardware(HostDto $host, array $hw, string $source): HostDto
    {
        $payload = json_decode($host->rawPayload, true);

        // Ako REST payload iz nekog razloga nije valjan JSON, cuva se kao
        // tekst umesto da se tiho izgubi.
        if (!is_array($payload)) {
            $payload = ['_rest' => $host->rawPayload];
        }

        $payload['_hardware'] = ['_source' => $source] + $hw;

        return new HostDto(
            moref:           $host->moref,
            name:            $host->name,
            connectionState: $host->connectionState,
            powerState:      $host->powerState,
            clusterMoref:    $host->clusterMoref,
            datacenterMoref: $host->datacenterMoref,
            rawPayload:      (string) json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            hardwareSource:  $source,
            vendor:          self::str($hw['vendor'] ?? null),
            model:           self::str($hw['model'] ?? null),
            serialNumber:    self::str($hw['serial_number'] ?? null),
            assetTag:        self::str($hw['asset_tag'] ?? null),
            cpuModel:        self::str($hw['cpu_model'] ?? null),
            cpuSockets:      self::int($hw['cpu_sockets'] ?? null),
            cpuCores:        self::int($hw['cpu_cores'] ?? null),
            cpuThreads:      self::int($hw['cpu_threads'] ?? null),
            cpuMhz:          self::int($hw['cpu_mhz'] ?? null),
            memoryBytes:     self::int($hw['memory_bytes'] ?? null),
            esxiVersion:     self::str($hw['esxi_version'] ?? null),
            esxiBuild:       self::str($hw['esxi_build'] ?? null),
            biosVersion:     self::str($hw['bios_version'] ?? null),
            hardwareUuid:    self::str($hw['hardware_uuid'] ?? null),
        );
    }

    private static function str(mixed $v): ?string
    {
        return ($v === null || $v === '') ? null : (string) $v;
    }

    private static function int(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }
}
