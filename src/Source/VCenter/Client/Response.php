<?php
// src/Source/VCenter/Client/Response.php
//
// Jedan HTTP odgovor. Sirov sadrzaj se cuva jer zavrsava u raw sloju baze,
// nedirnut — validacija je posao mapera, ne skladista.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use JsonException;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $raw,
        public readonly array $headers = [],
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Dekodiran JSON, ili null ako telo nije ispravan JSON.
     */
    public function json(): mixed
    {
        if ($this->raw === '') {
            return null;
        }

        try {
            return json_decode($this->raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Poruka o gresci iz tela odgovora, skracena za prikaz.
     *
     * vSphere vraca gresku kao {"error_type": "...", "messages": [{"default_message": "..."}]}
     * u /api dijalektu, odnosno pod kljucem "value" u /rest dijalektu.
     */
    public function errorMessage(): string
    {
        $body = $this->json();

        if (!is_array($body)) {
            return '';
        }

        $envelope = $body['value'] ?? $body;

        if (is_array($envelope) && isset($envelope['messages']) && is_array($envelope['messages'])) {
            foreach ($envelope['messages'] as $message) {
                if (is_array($message) && isset($message['default_message'])) {
                    return mb_substr((string) $message['default_message'], 0, 300);
                }
            }
        }

        if (is_array($envelope) && isset($envelope['error_type'])) {
            return (string) $envelope['error_type'];
        }

        return '';
    }

    /**
     * Prepoznaje gresku "previse objekata" koju vSphere vraca kada lista
     * premasi limit (4000 VM-ova, 1000 hostova).
     */
    public function isTooManyObjects(): bool
    {
        $body = $this->json();

        if (!is_array($body)) {
            return false;
        }

        $envelope = $body['value'] ?? $body;
        $type     = is_array($envelope) ? ($envelope['error_type'] ?? '') : '';

        return str_contains(strtoupper((string) $type), 'UNABLE_TO_ALLOCATE_RESOURCE')
            || str_contains($this->raw, 'unable_to_allocate_resource');
    }
}
