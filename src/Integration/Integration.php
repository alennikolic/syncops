<?php
// src/Integration/Integration.php
//
// Jedna definicija izvora podataka (u ovoj fazi: jedan vCenter).
//
// NAMERNO NE SADRZI LOZINKU. Lozinka se cita zasebnim pozivom
// IntegrationRepository::password() i to tek u trenutku kada klijent treba da
// se prijavi. Time ne moze da zavrsi u var_dump-u, u ispisu izuzetka ni u
// logu samo zato sto je neko ispisao objekat integracije.

declare(strict_types=1);

namespace SyncOps\Integration;

final class Integration
{
    public const FLAVOR_AUTO        = 'auto';
    public const FLAVOR_REST_LEGACY = 'rest_legacy';   // /rest    (6.7 - 7.0)
    public const FLAVOR_API_V2      = 'api_v2';        // /api     (7.0U2+)

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $hostname,
        public readonly int $port,
        public readonly string $username,
        public readonly string $apiFlavor,
        public readonly ?string $apiFlavorDetected,
        public readonly ?string $productVersion,
        public readonly bool $verifyPeer,
        public readonly bool $isActive,
        public readonly ?string $lastTestedAt,
        public readonly ?bool $lastTestOk,
        public readonly ?string $lastTestMessage,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * Napravi objekat iz reda u bazi.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                (int) $row['id'],
            type:              (string) $row['type'],
            name:              (string) $row['name'],
            hostname:          (string) $row['hostname'],
            port:              (int) $row['port'],
            username:          (string) $row['username'],
            apiFlavor:         (string) $row['api_flavor'],
            apiFlavorDetected: $row['api_flavor_detected'] !== null ? (string) $row['api_flavor_detected'] : null,
            productVersion:    $row['product_version'] !== null ? (string) $row['product_version'] : null,
            verifyPeer:        (bool) $row['verify_peer'],
            isActive:          (bool) $row['is_active'],
            lastTestedAt:      $row['last_tested_at'] !== null ? (string) $row['last_tested_at'] : null,
            lastTestOk:        $row['last_test_ok'] !== null ? (bool) $row['last_test_ok'] : null,
            lastTestMessage:   $row['last_test_message'] !== null ? (string) $row['last_test_message'] : null,
            createdAt:         (string) $row['created_at'],
            updatedAt:         (string) $row['updated_at'],
        );
    }

    /**
     * Osnovna adresa API-ja, bez zavrsne kose crte.
     * Port 443 se izostavlja, da URL izgleda kako ga ljudi ocekuju.
     */
    public function baseUrl(): string
    {
        return $this->port === 443
            ? 'https://' . $this->hostname
            : 'https://' . $this->hostname . ':' . $this->port;
    }

    /**
     * Dijalekt koji treba stvarno koristiti: rucno podesen ako nije "auto",
     * inace poslednji detektovani. NULL znaci da detekcija jos nije radjena.
     */
    public function effectiveFlavor(): ?string
    {
        if ($this->apiFlavor !== self::FLAVOR_AUTO) {
            return $this->apiFlavor;
        }

        return $this->apiFlavorDetected;
    }
}
