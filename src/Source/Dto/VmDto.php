<?php
// src/Source/Dto/VmDto.php
//
// Normalizovana virtuelna masina, spremna za upis u vc_vm.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class VmDto
{
    public const GUEST_AVAILABLE        = 'available';
    public const GUEST_TOOLS_NOT_RUNNING = 'tools_not_running';
    public const GUEST_POWERED_OFF      = 'powered_off';
    public const GUEST_UNAVAILABLE      = 'unavailable';

    public function __construct(
        public readonly string $moref,
        public readonly ?string $instanceUuid,
        public readonly ?string $biosUuid,
        public readonly string $name,
        public readonly ?string $powerState,
        public readonly bool $isTemplate,
        public readonly ?string $guestOs,
        public readonly ?string $hardwareVersion,
        public readonly ?int $cpuCount,
        public readonly ?int $cpuCoresPerSocket,
        public readonly ?int $memoryMib,
        public readonly ?int $diskCount,
        public readonly ?int $diskCapacityBytes,
        public readonly ?int $nicCount,

        // --- Podaci iz gosta -------------------------------------------
        public readonly ?string $guestState,
        public readonly ?string $guestHostname,
        public readonly ?string $guestIpAddress,
        public readonly ?string $guestOsFamily,
        public readonly ?string $guestFullName,

        // --- Smestaj ----------------------------------------------------
        public readonly ?string $hostMoref,
        public readonly ?string $clusterMoref,
        public readonly ?string $folderMoref,
        public readonly ?string $datacenterMoref,

        public readonly string $rawPayload,
    ) {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'name'                 => $this->name,
            'instance_uuid'        => $this->instanceUuid,
            'bios_uuid'            => $this->biosUuid,
            'power_state'          => $this->powerState,
            'is_template'          => $this->isTemplate,
            'guest_os'             => $this->guestOs,
            'hardware_version'     => $this->hardwareVersion,
            'cpu_count'            => $this->cpuCount,
            'cpu_cores_per_socket' => $this->cpuCoresPerSocket,
            'memory_mib'           => $this->memoryMib,
            'disk_count'           => $this->diskCount,
            'disk_capacity_bytes'  => $this->diskCapacityBytes,
            'nic_count'            => $this->nicCount,
            'guest_state'          => $this->guestState,
            'guest_hostname'       => $this->guestHostname,
            'guest_ip_address'     => $this->guestIpAddress,
            'guest_os_family'      => $this->guestOsFamily,
            'guest_full_name'      => $this->guestFullName,
            'host_moref'           => $this->hostMoref,
            'cluster_moref'        => $this->clusterMoref,
            'folder_moref'         => $this->folderMoref,
            'datacenter_moref'     => $this->datacenterMoref,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->comparable(), JSON_THROW_ON_ERROR));
    }
}
