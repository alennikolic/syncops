<?php
// src/Source/VCenter/VCenterException.php
//
// Greska u komunikaciji sa vCenterom, sa porukom koja je upotrebljiva i u
// GUI-ju (upisuje se u integration.last_test_message).
//
// Poruke NIKADA ne sadrze lozinku ni sesijski token.

declare(strict_types=1);

namespace SyncOps\Source\VCenter;

use RuntimeException;
use Throwable;

final class VCenterException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public static function unreachable(string $host, string $detail): self
    {
        return new self("vCenter {$host} nije dostupan: {$detail}");
    }

    public static function tls(string $host, string $detail): self
    {
        return new self(
            "TLS sertifikat vCentera {$host} nije prihvacen ({$detail}). "
            . 'Iskljuci proveru sertifikata za ovu integraciju ili dodaj CA u kontejner.'
        );
    }

    public static function badCredentials(): self
    {
        return new self('Pogresno korisnicko ime ili lozinka.', 401);
    }

    public static function forbidden(string $what): self
    {
        return new self(
            "Nalog nema prava za {$what}. Potrebna je Read-only rola na root objektu, sa propagacijom.",
            403
        );
    }

    public static function wrongFlavor(string $path): self
    {
        return new self(
            "Putanja {$path} ne postoji na ovom vCenteru — verovatno je izabran pogresan dijalekt API-ja.",
            404
        );
    }

    public static function tooManyObjects(string $what, int $limit): self
    {
        return new self(
            "vCenter odbija da vrati listu: {$what} ima vise od {$limit}. "
            . 'Upit mora biti particionisan po datacentru ili klasteru.'
        );
    }

    public static function unexpected(int $status, string $path, string $detail = ''): self
    {
        $suffix = $detail !== '' ? ": {$detail}" : '';

        return new self("vCenter je na {$path} vratio HTTP {$status}{$suffix}", $status);
    }
}
