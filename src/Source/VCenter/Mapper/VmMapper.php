<?php
// src/Source/VCenter/Mapper/VmMapper.php
//
// Odgovor API-ja -> VmDto.
//
// Spaja tri izvora:
//   1. detalji masine   GET /vcenter/vm/{vm}            -> identity, cpu, memory, disks, nics
//   2. podaci iz gosta  GET /vcenter/vm/{vm}/guest/identity -> hostname, IP, stvarni OS
//   3. smestaj          izvedeno iz filtriranih listi    -> host, klaster, datacentar
//
// Napomena o templejtima: /vcenter/vm NE vraca VM templejte uopste, pa je
// is_template uvek false dok se ne doda drugi izvor. Kolona postoji da bi SOAP
// klijent ili Content Library kasnije mogli da je popune.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\VmDto;

final class VmMapper
{
    /**
     * @param array<string, mixed> $detail   odgovor getVm()
     * @param array<string, mixed>|null $guest odgovor getVmGuestIdentity(), null ako Tools ne rade
     * @param array{host: ?string, cluster: ?string, folder: ?string, datacenter: ?string} $placement
     */
    public function map(string $moref, array $detail, ?array $guest, array $placement): VmDto
    {
        $identity = is_array($detail['identity'] ?? null) ? $detail['identity'] : [];
        $cpu      = is_array($detail['cpu'] ?? null) ? $detail['cpu'] : [];
        $memory   = is_array($detail['memory'] ?? null) ? $detail['memory'] : [];
        $hardware = is_array($detail['hardware'] ?? null) ? $detail['hardware'] : [];

        $disks = is_array($detail['disks'] ?? null) ? $detail['disks'] : [];
        $nics  = is_array($detail['nics'] ?? null) ? $detail['nics'] : [];

        $powerState = isset($detail['power_state']) ? (string) $detail['power_state'] : null;

        $payload = [
            'detail'    => $detail,
            'guest'     => $guest,
            'placement' => $placement,
        ];

        return new VmDto(
            moref:             $moref,
            instanceUuid:      self::str($identity['instance_uuid'] ?? null),
            biosUuid:          self::str($identity['bios_uuid'] ?? null),
            name:              (string) ($detail['name'] ?? $identity['name'] ?? ''),
            powerState:        $powerState,
            // REST ne vraca templejte uopste, pa polje kod njega ne postoji i
            // ostaje false. SOAP ga daje (config.template), i tada je stvaran.
            isTemplate:        (bool) ($detail['is_template'] ?? false),
            guestOs:           self::str($detail['guest_OS'] ?? $detail['guest_os'] ?? null),
            hardwareVersion:   self::str($hardware['version'] ?? null),

            cpuCount:          self::int($cpu['count'] ?? null),
            cpuCoresPerSocket: self::int($cpu['cores_per_socket'] ?? null),
            memoryMib:         self::int($memory['size_MiB'] ?? $memory['size_mib'] ?? null),

            diskCount:         $disks === [] ? null : count($disks),
            diskCapacityBytes: self::sumDiskCapacity($disks),
            nicCount:          $nics === [] ? null : count($nics),

            guestState:        self::guestState($guest, $powerState),
            guestHostname:     self::str($guest['host_name'] ?? null),
            guestIpAddress:    self::str($guest['ip_address'] ?? null),
            guestOsFamily:     self::str($guest['family'] ?? null),
            guestFullName:     self::localizable($guest['full_name'] ?? null),

            hostMoref:         $placement['host'] ?? null,
            clusterMoref:      $placement['cluster'] ?? null,
            folderMoref:       $placement['folder'] ?? null,
            datacenterMoref:   $placement['datacenter'] ?? null,

            rawPayload:        (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Zbir kapaciteta svih diskova. Diskovi dolaze kao mapa id => {capacity: ...}.
     *
     * @param array<array-key, mixed> $disks
     */
    private static function sumDiskCapacity(array $disks): ?int
    {
        if ($disks === []) {
            return null;
        }

        $total = 0;
        $found = false;

        foreach ($disks as $disk) {
            if (!is_array($disk)) {
                continue;
            }

            // /api: capacity je u korenu diska; kod nekih verzija pod "backing".
            $capacity = $disk['capacity'] ?? ($disk['backing']['capacity'] ?? null);

            if ($capacity !== null) {
                $total += (int) $capacity;
                $found  = true;
            }
        }

        return $found ? $total : null;
    }

    /**
     * Zasto podaci iz gosta jesu ili nisu prikupljeni. Bez ovoga se u GUI-ju
     * ne razlikuje "Tools ne rade" od "nismo ni pokusali".
     *
     * @param array<string, mixed>|null $guest
     */
    private static function guestState(?array $guest, ?string $powerState): string
    {
        if ($guest !== null && ($guest['host_name'] ?? $guest['ip_address'] ?? null) !== null) {
            return VmDto::GUEST_AVAILABLE;
        }

        if ($powerState !== null && $powerState !== 'POWERED_ON') {
            return VmDto::GUEST_POWERED_OFF;
        }

        if ($guest === null) {
            return VmDto::GUEST_TOOLS_NOT_RUNNING;
        }

        return VmDto::GUEST_UNAVAILABLE;
    }

    /**
     * vSphere neka polja vraca kao LocalizableMessage:
     * {"default_message": "...", "id": "...", "args": []}
     */
    private static function localizable(mixed $value): ?string
    {
        if (is_array($value)) {
            return self::str($value['default_message'] ?? null);
        }

        return self::str($value);
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
