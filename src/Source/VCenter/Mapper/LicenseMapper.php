<?php
// src/Source/VCenter/Mapper/LicenseMapper.php
//
// Odgovor vCentera -> LicenseDto.
//
// Sta LicenseManager daje po licenci: licenseKey, editionKey, name, total,
// used, costUnit i niz properties. Iz properties se uzimaju samo skalarne
// vrednosti (ProductName, ProductVersion, expirationDate); feature zapisi i
// ugnezdjeni LicenseInfo blokovi su vec odbaceni u klijentu.
//
// Mereno na stvarnom odgovoru: used i costUnit izostaju kod eval licence, a
// rok ima samo deo licenci. Zato su sva tri nullable, a ne praznim stringom
// popunjena — prazno i "nema podatka" nisu ista stvar.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Mapper;

use SyncOps\Source\Dto\LicenseDto;

final class LicenseMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row): LicenseDto
    {
        /** @var array<string, string> $properties */
        $properties = is_array($row['properties'] ?? null) ? $row['properties'] : [];

        return new LicenseDto(
            licenseKey:     (string) ($row['key'] ?? ''),
            name:           (string) ($row['name'] ?? ''),
            editionKey:     $this->text($row['edition_key'] ?? null),
            productName:    $this->text($properties['ProductName'] ?? null),
            productVersion: $this->text($properties['ProductVersion'] ?? null),
            total:          $this->number($row['total'] ?? null),
            used:           $this->number($row['used'] ?? null),
            costUnit:       $this->text($row['cost_unit'] ?? null),
            expiresAt:      $this->datetime($properties['expirationDate'] ?? null),
            rawPayload:     (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    /**
     * vCenter salje rok kao 2025-07-11T00:00:00Z; baza ocekuje DATETIME.
     *
     * Vrednost je vec u UTC-u, kao i sve ostalo u bazi, pa se samo menja
     * oblik zapisa. Ako stigne nesto neocekivano, vraca se null umesto da
     * se u kolonu upise smece.
     */
    private function datetime(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        $time = strtotime($text);

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
