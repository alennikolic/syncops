<?php
// src/Source/Dto/HostDto.php
//
// Normalizovan ESXi host, spreman za upis u vc_host.
//
// Hardverska polja su sva nullable i u REST fazi ostaju prazna — REST ih
// jednostavno nema. hardwareSource razlikuje "nije prikupljeno" (null) od
// "prikupljeno pa prazno", da se u GUI-ju ne meša jedno sa drugim.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class HostDto
{
    public const SOURCE_REST = 'rest';
    public const SOURCE_SOAP = 'soap';

    public function __construct(
        public readonly string $moref,
        public readonly string $name,
        public readonly ?string $connectionState,
        public readonly ?string $powerState,
        public readonly ?string $clusterMoref,
        public readonly ?string $datacenterMoref,
        public readonly string $rawPayload,

        // --- Hardver: popunjava SOAP klijent ---------------------------
        public readonly ?string $hardwareSource = null,
        public readonly ?string $vendor = null,
        public readonly ?string $model = null,
        public readonly ?string $serialNumber = null,
        public readonly ?string $assetTag = null,
        public readonly ?string $cpuModel = null,
        public readonly ?int $cpuSockets = null,
        public readonly ?int $cpuCores = null,
        public readonly ?int $cpuThreads = null,
        public readonly ?int $cpuMhz = null,
        public readonly ?int $memoryBytes = null,
        public readonly ?string $esxiVersion = null,
        public readonly ?string $esxiBuild = null,
        public readonly ?string $biosVersion = null,
        public readonly ?string $hardwareUuid = null,
    ) {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'name'             => $this->name,
            'connection_state' => $this->connectionState,
            'power_state'      => $this->powerState,
            'cluster_moref'    => $this->clusterMoref,
            'datacenter_moref' => $this->datacenterMoref,
            'hardware_source'  => $this->hardwareSource,
            'vendor'           => $this->vendor,
            'model'            => $this->model,
            'serial_number'    => $this->serialNumber,
            'asset_tag'        => $this->assetTag,
            'cpu_model'        => $this->cpuModel,
            'cpu_sockets'      => $this->cpuSockets,
            'cpu_cores'        => $this->cpuCores,
            'cpu_threads'      => $this->cpuThreads,
            'cpu_mhz'          => $this->cpuMhz,
            'memory_bytes'     => $this->memoryBytes,
            'esxi_version'     => $this->esxiVersion,
            'esxi_build'       => $this->esxiBuild,
            'bios_version'     => $this->biosVersion,
            'hardware_uuid'    => $this->hardwareUuid,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->comparable(), JSON_THROW_ON_ERROR));
    }
}
