<?php
// src/Source/Dto/HostDeviceDto.php
//
// Normalizovan uredjaj hosta, spreman za upis u vc_host_device.
//
// Jedan DTO za pet vrsta: fizicku mreznu karticu, VMkernel adapter,
// standardni svic, port grupu i HBA. Vrsta je u polju kind, a polja koja joj
// ne pripadaju ostaju null — isto kao u tabeli, koja je unija kolona.
//
// Identitet se SKLAPA. Ovi uredjaji nemaju moref; postoje samo kao stavke u
// konfiguraciji hosta. Kljuc je host, vrsta i ime uredjaja. Vrsta je u kljucu
// jer se ime uredjaja i ime port grupe mogu poklopiti.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class HostDeviceDto implements InventoryDto
{
    public const KIND_PNIC      = 'pnic';
    public const KIND_VMK       = 'vmk';
    public const KIND_VSWITCH   = 'vswitch';
    public const KIND_PORTGROUP = 'portgroup';
    public const KIND_HBA       = 'hba';

    /** Naziv vrste za prikaz. */
    public const LABELS = [
        self::KIND_PNIC      => 'Mrežna kartica',
        self::KIND_VMK       => 'VMkernel adapter',
        self::KIND_VSWITCH   => 'Standardni svič',
        self::KIND_PORTGROUP => 'Port grupa',
        self::KIND_HBA       => 'HBA',
    ];

    public function __construct(
        public readonly string $hostMoref,
        public readonly string $kind,
        public readonly string $device,
        public readonly ?string $mac = null,
        public readonly ?string $driver = null,
        public readonly ?string $driverVersion = null,
        public readonly ?string $firmwareVersion = null,
        public readonly ?int $speedMb = null,
        public readonly ?bool $duplex = null,
        public readonly ?string $pci = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $subnetMask = null,
        public readonly ?string $gateway = null,
        public readonly ?bool $dhcp = null,
        public readonly ?int $mtu = null,
        public readonly ?string $portGroup = null,
        public readonly ?int $numPorts = null,
        public readonly ?int $numPortsAvailable = null,
        public readonly ?int $vlanId = null,
        public readonly ?string $vswitchName = null,
        public readonly ?string $hbaType = null,
        public readonly ?string $model = null,
        public readonly ?string $status = null,
        public readonly ?string $storageProtocol = null,
        public readonly ?string $wwpn = null,
        public readonly ?string $wwnn = null,
        public readonly ?string $iscsiName = null,
        public readonly string $rawPayload = '{}',
    ) {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'host_moref'          => $this->hostMoref,
            'kind'                => $this->kind,
            'device'              => $this->device,
            'mac'                 => $this->mac,
            'driver'              => $this->driver,
            'driver_version'      => $this->driverVersion,
            'firmware_version'    => $this->firmwareVersion,
            'speed_mb'            => $this->speedMb,
            'duplex'              => $this->duplex,
            'pci'                 => $this->pci,
            'ip_address'          => $this->ipAddress,
            'subnet_mask'         => $this->subnetMask,
            'gateway'             => $this->gateway,
            'dhcp'                => $this->dhcp,
            'mtu'                 => $this->mtu,
            'port_group'          => $this->portGroup,
            'num_ports'           => $this->numPorts,
            'num_ports_available' => $this->numPortsAvailable,
            'vlan_id'             => $this->vlanId,
            'vswitch_name'        => $this->vswitchName,
            'hba_type'            => $this->hbaType,
            'model'               => $this->model,
            'status'              => $this->status,
            'storage_protocol'    => $this->storageProtocol,
            'wwpn'                => $this->wwpn,
            'wwnn'                => $this->wwnn,
            'iscsi_name'          => $this->iscsiName,
        ];
    }

    /**
     * Sklopljen kljuc: host, vrsta i ime uredjaja.
     *
     * Vrsta je unutra jer se ime port grupe i ime uredjaja mogu poklopiti —
     * port grupa sme da se zove "vSwitch0".
     */
    public function moref(): string
    {
        return $this->hostMoref . ':' . $this->kind . ':' . $this->device;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->comparable(), JSON_THROW_ON_ERROR));
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }
}
