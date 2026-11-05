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
    // vCenter se cita iskljucivo preko vim25 (/sdk). REST dijalekti su
    // uklonjeni u migraciji 0010; stare vrednosti su tada prevedene na soap.
    public const FLAVOR_SOAP = 'soap';          // /sdk  (vim25, sve verzije)

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
     * Dijalekt kojim se ova integracija cita.
     *
     * Ranije je ovde zivela logika za "auto": ako je dijalekt bio nepoznat,
     * uzimala se poslednja detektovana vrednost. Sa jednim protokolom biranja
     * vise nema, pa metoda ostaje samo kao citljivo ime za kolonu — i kao
     * mesto na kom ce se, kada stignu drugi izvori, njihovi dijalekti
     * razlikovati.
     */
    public function effectiveFlavor(): string
    {
        return $this->apiFlavor;
    }
}
