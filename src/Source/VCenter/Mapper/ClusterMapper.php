<?php
// src/Source/VCenter/Mapper/ClusterMapper.php
//
// Odgovor API-ja -> ClusterDto.
//
// Sta /vcenter/cluster daje: cluster, name, ha_enabled, drs_enabled.
// Datacentar nije deo odgovora, pa ga kolektor prosledjuje odvojeno — zna ga
// jer je liste klastera i trazio po datacentru.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\ClusterDto;

final class ClusterMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, ?string $datacenterMoref, ?string $datacenterName): ClusterDto
    {
        $payload = $row + [
            '_datacenter' => ['moref' => $datacenterMoref, 'name' => $datacenterName],
        ];

        return new ClusterDto(
            moref:           (string) ($row['cluster'] ?? ''),
            name:            (string) ($row['name'] ?? ''),
            datacenterMoref: $datacenterMoref,
            datacenterName:  $datacenterName,
            haEnabled:       isset($row['ha_enabled']) ? (bool) $row['ha_enabled'] : null,
            drsEnabled:      isset($row['drs_enabled']) ? (bool) $row['drs_enabled'] : null,
            rawPayload:      (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
