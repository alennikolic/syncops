<?php
// src/Source/Dto/LicenseDto.php
//
// Normalizovana licenca, spremna za upis u vc_license.
//
// Polja odgovaraju kolonama tabele jedan na jedan, kao i kod ostalih DTO-a.
//
// Identitet je licencni kljuc. Licenca nije objekat u stablu inventara i nema
// moref, pa moref() vraca kljuc — tako licenca ulazi u isti InventoryRepository
// koji vec upisuje masine, hostove i klastere.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

final class LicenseDto implements InventoryDto
{
    public function __construct(
        public readonly string $licenseKey,
        public readonly string $name,
        public readonly ?string $editionKey,
        public readonly ?string $productName,
        public readonly ?string $productVersion,
        public readonly ?int $total,
        public readonly ?int $used,
        public readonly ?string $costUnit,
        public readonly ?string $expiresAt,
        public readonly string $rawPayload,
    ) {
    }

    /**
     * Vrednosti koje ulaze u payload_hash i u journal promena.
     *
     * used je tu namerno: broj zauzetih mesta se menja kad se host doda ili
     * skloni, i upravo se to i prati. Svaka takva promena je jedan red u
     * vc_license_change.
     *
     * @return array<string, scalar|null>
     */
    public function comparable(): array
    {
        return [
            'name'            => $this->name,
            'edition_key'     => $this->editionKey,
            'product_name'    => $this->productName,
            'product_version' => $this->productVersion,
            'total'           => $this->total,
            'used'            => $this->used,
            'cost_unit'       => $this->costUnit,
            'expires_at'      => $this->expiresAt,
        ];
    }

    public function moref(): string
    {
        return $this->licenseKey;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->comparable(), JSON_THROW_ON_ERROR));
    }

    /** Licenca bez gornje granice; vCenter to javlja kao total = 0. */
    public function isUnlimited(): bool
    {
        return $this->total === 0;
    }

    /** Rok je prosao. Licenca bez roka nikada nije istekla. */
    public function isExpired(?string $nowUtc = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < ($nowUtc ?? gmdate('Y-m-d H:i:s'));
    }
}
