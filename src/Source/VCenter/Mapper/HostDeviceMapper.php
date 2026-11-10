<?php
// src/Source/VCenter/Mapper/HostDeviceMapper.php
//
// Odgovor vCentera -> HostDeviceDto, za svih pet vrsta uredjaja.
//
// Klijent vec vraca ravan niz po uredjaju, sa kljucevima imenovanim po
// kolonama; ovde se vrednosti samo svode na tipove i, kod WWN-a, na oblik
// koji se svuda koristi.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\HostDeviceDto;

final class HostDeviceMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row): HostDeviceDto
    {
        return new HostDeviceDto(
            hostMoref:         (string) ($row['host_moref'] ?? ''),
            kind:              (string) ($row['kind'] ?? ''),
            device:            (string) ($row['device'] ?? ''),
            mac:               $this->text($row['mac'] ?? null),
            driver:            $this->text($row['driver'] ?? null),
            driverVersion:     $this->text($row['driver_version'] ?? null),
            firmwareVersion:   $this->text($row['firmware_version'] ?? null),
            speedMb:           $this->number($row['speed_mb'] ?? null),
            duplex:            $this->flag($row['duplex'] ?? null),
            pci:               $this->text($row['pci'] ?? null),
            ipAddress:         $this->text($row['ip_address'] ?? null),
            subnetMask:        $this->text($row['subnet_mask'] ?? null),
            gateway:           $this->text($row['gateway'] ?? null),
            dhcp:              $this->flag($row['dhcp'] ?? null),
            mtu:               $this->number($row['mtu'] ?? null),
            portGroup:         $this->text($row['port_group'] ?? null),
            numPorts:          $this->number($row['num_ports'] ?? null),
            numPortsAvailable: $this->number($row['num_ports_available'] ?? null),
            // VLAN 0 je ispravna vrednost (bez VLAN-a), pa se ne sme svesti na null.
            vlanId:            $this->number($row['vlan_id'] ?? null),
            vswitchName:       $this->text($row['vswitch_name'] ?? null),
            hbaType:           $this->text($row['hba_type'] ?? null),
            model:             $this->text($row['model'] ?? null),
            status:            $this->text($row['status'] ?? null),
            storageProtocol:   $this->text($row['storage_protocol'] ?? null),
            wwpn:              $this->wwn($row['wwpn'] ?? null),
            wwnn:              $this->wwn($row['wwnn'] ?? null),
            iscsiName:         $this->text($row['iscsi_name'] ?? null),
            rawPayload:        (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * WWN u oblik koji se svuda i koristi: 21:00:f4:e9:d4:55:b9:00.
     *
     * FC adapter javlja decimalni broj (2378169888378566912), SAS vec
     * heksadecimalni string (500e0004000bca70). Oba zavrse isto.
     */
    private function wwn(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        if (ctype_digit($text)) {
            // 64-bitni broj staje u PHP int; decimalni zapis je najvise 20
            // cifara, a PHP_INT_MAX je 19, pa se duzi ostavlja kakav jeste.
            if (strlen($text) > 19) {
                return $text;
            }

            $text = sprintf('%016x', (int) $text);
        }

        $hex = strtolower(preg_replace('~[^0-9a-fA-F]~', '', $text) ?? '');

        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return $text;
        }

        return implode(':', str_split($hex, 2));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function number(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    /** vim25 salje logicke vrednosti kao "true"/"false". */
    private function flag(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return (string) $value === 'true' || (string) $value === '1';
    }
}
