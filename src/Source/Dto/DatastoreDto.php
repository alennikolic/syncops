<?php
// src/Source/Dto/DatastoreDto.php
//
// Normalizovan datastore, spreman za upis u vc_datastore.
//
// Polja odgovaraju kolonama tabele jedan na jedan, kao i kod ostalih DTO-a.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class DatastoreDto implements InventoryDto
{
    public function __construct(
        public readonly string $moref,
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?int $capacityBytes,
        public readonly ?int $freeBytes,
        public readonly ?int $uncommittedBytes,
        public readonly ?bool $accessible,
        public readonly ?bool $multipleHostAccess,
        public readonly ?string $maintenanceMode,
        public readonly ?string $overallStatus,
        public readonly ?string $url,
        public readonly ?int $hostCount,
        public readonly ?string $vmfsUuid,
        public readonly ?string $vmfsVersion,
        public readonly string $rawPayload,
    ) {
    }

    /**
     * Vrednosti koje ulaze u payload_hash i u journal promena.
     *
     * free_bytes i uncommitted_bytes su tu namerno. Zauzece se menja izmedju
     * svakog prikupljanja, pa ce gotovo svaki run upisati red u journal — to
     * je cena istorije zauzeca, koja je i bila cilj.
     *
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'name'                 => $this->name,
            'type'                 => $this->type,
            'capacity_bytes'       => $this->capacityBytes,
            'free_bytes'           => $this->freeBytes,
            'uncommitted_bytes'    => $this->uncommittedBytes,
            'is_accessible'        => $this->accessible,
            'multiple_host_access' => $this->multipleHostAccess,
            'maintenance_mode'     => $this->maintenanceMode,
            'overall_status'       => $this->overallStatus,
            'url'                  => $this->url,
            'host_count'           => $this->hostCount,
            'vmfs_uuid'            => $this->vmfsUuid,
            'vmfs_version'         => $this->vmfsVersion,
        ];
    }

    public function moref(): string
    {
        return $this->moref;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->comparable(), JSON_THROW_ON_ERROR));
    }

    /** Zauzeto u bajtovima, ili null kada vCenter nije dao obe vrednosti. */
    public function usedBytes(): ?int
    {
        if ($this->capacityBytes === null || $this->freeBytes === null) {
            return null;
        }

        return max(0, $this->capacityBytes - $this->freeBytes);
    }

    /**
     * Popunjenost u procentima, zaokruzena na ceo broj.
     *
     * Kapacitet 0 nije greska: vSAN bez diskova ga tako javlja. Tada nema sta
     * da se racuna, pa se vraca null umesto deljenja nulom.
     */
    public function usedPercent(): ?int
    {
        $used = $this->usedBytes();

        if ($used === null || $this->capacityBytes === null || $this->capacityBytes <= 0) {
            return null;
        }

        return (int) round($used * 100 / $this->capacityBytes);
    }
}
