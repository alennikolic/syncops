<?php
// src/Source/VCenter/Mapper/DatastoreMapper.php
//
// Odgovor vCentera -> DatastoreDto.
//
// Sta Datastore daje: name, summary.* (tip, kapacitet, slobodno, neupisano,
// dostupnost, maintenance, url), overallStatus, spisak hostova koji ga
// montiraju i info blok koji se razlikuje po tipu.
//
// Mereno na stvarnom odgovoru: uncommitted izostaje tamo gde nema tanko
// dodeljenih diskova, pa je nullable. Kapacitet 0 nije greska — vSAN bez
// diskova ga tako javlja.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\DatastoreDto;

final class DatastoreMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row): DatastoreDto
    {
        /** @var list<string> $hosts */
        $hosts = is_array($row['hosts'] ?? null) ? array_values($row['hosts']) : [];

        return new DatastoreDto(
            moref:              (string) ($row['datastore'] ?? ''),
            name:               (string) ($row['name'] ?? ''),
            type:               $this->text($row['type'] ?? null),
            capacityBytes:      $this->number($row['capacity'] ?? null),
            freeBytes:          $this->number($row['free_space'] ?? null),
            uncommittedBytes:   $this->number($row['uncommitted'] ?? null),
            accessible:         $this->flag($row['accessible'] ?? null),
            multipleHostAccess: $this->flag($row['multiple_host_access'] ?? null),
            maintenanceMode:    $this->text($row['maintenance_mode'] ?? null),
            overallStatus:      $this->text($row['overall_status'] ?? null),
            url:                $this->text($row['url'] ?? null),
            // Null a ne 0 kada spisak uopste nije stigao: "nijedan host" i
            // "vCenter nije rekao" nisu ista stvar.
            hostCount:          isset($row['hosts']) ? count($hosts) : null,
            vmfsUuid:           $this->text($row['vmfs_uuid'] ?? null),
            vmfsVersion:        $this->text($row['vmfs_version'] ?? null),
            rawPayload:         (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
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
