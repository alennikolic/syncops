<?php
// src/Source/Dto/ClusterDto.php
//
// Normalizovan klaster, spreman za upis u vc_cluster.
//
// Polja odgovaraju kolonama tabele jedan na jedan. Sve sto dolazi iz API-ja
// prolazi kroz maper i zavrsava ovde; kolektori i skladiste dalje ne znaju
// nista o obliku odgovora ni o verziji API-ja.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class ClusterDto implements InventoryDto
{
    public function __construct(
        public readonly string $moref,
        public readonly string $name,
        public readonly ?string $datacenterMoref,
        public readonly ?string $datacenterName,
        public readonly ?bool $haEnabled,
        public readonly ?bool $drsEnabled,
        public readonly string $rawPayload,
    ) {
    }

    /**
     * Vrednosti koje ulaze u payload_hash i u journal promena.
     *
     * Hesira se OVO, a ne sirov odgovor API-ja: inace bi svako polje koje
     * vCenter vrati a mi ga ne cuvamo pravilo laznu promenu u journalu.
     *
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'name'             => $this->name,
            'datacenter_moref' => $this->datacenterMoref,
            'datacenter_name'  => $this->datacenterName,
            'ha_enabled'       => $this->haEnabled,
            'drs_enabled'      => $this->drsEnabled,
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
}
