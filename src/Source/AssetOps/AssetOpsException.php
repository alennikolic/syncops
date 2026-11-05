<?php
// src/Source/AssetOps/AssetOpsException.php
//
// Greske u radu sa AssetOPS API-jem, sa porukama koje kazu sta da se uradi.
//
// Imenovani konstruktori a ne golo bacanje: poruka ide u GUI i u
// integration.last_test_message, pa mora da bude recenica koju citalac
// razume bez gledanja u kod.
//
// NIJEDNA poruka ne sme da sadrzi API kljuc. Kljuc stize kroz zaglavlje i
// nigde se ne ispisuje — ni u poruci, ni u tragu izuzetka.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use RuntimeException;
use Throwable;

final class AssetOpsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $host, string $detail): self
    {
        return new self("AssetOPS na {$host} nije dostupan: {$detail}");
    }

    public static function tls(string $host, string $detail): self
    {
        return new self(
            "TLS provera za {$host} nije prosla: {$detail}. "
            . 'Ako instanca ima self-signed sertifikat, iskljuci proveru na ovoj integraciji.',
            null
        );
    }

    /**
     * 401 sa porukom da kredencijal nije ni prepoznat — zaglavlje nije stiglo
     * ili je prazno.
     */
    public static function noCredentials(): self
    {
        return new self(
            'AssetOPS nije prepoznao kredencijal. Proveri da je API kljuc unet i da nije opozvan.',
            401
        );
    }

    /**
     * 401 sa code=token_not_valid: server je pokusao da kljuc procita kao JWT.
     *
     * U praksi to znaci da je u polje za kljuc unet JWT token, jer SyncOPS
     * uvek salje Api-Key. Poruka to kaze, jer sama poruka servera zvuci kao
     * da je kljuc istekao — a nije.
     */
    public static function looksLikeJwt(): self
    {
        return new self(
            'AssetOPS je ovaj kredencijal pokusao da procita kao JWT token i odbio ga. '
            . 'SyncOPS koristi API kljuc (oblik prefiks.tajna), ne token sa /api/token/.',
            401
        );
    }

    public static function forbidden(string $path): self
    {
        return new self(
            "Kljuc nema pravo pristupa na {$path}. Proveri prava korisnika za kog je izdat "
            . 'i njegove visibility grupe.',
            403
        );
    }

    /**
     * HTTP 200 ali "data": null — AssetOPS tako odbija parametar.
     *
     * Ovo je najvaznija greska u celom klijentu: bez nje bi odbijeni upit
     * prosao kao prazan rezultat, a prazan rezultat u sinhronizaciji znaci
     * "sve je nestalo".
     */
    public static function rejected(string $path, string $query): self
    {
        return new self(
            "AssetOPS je odbio upit na {$path}" . ($query === '' ? '' : " ({$query})")
            . ' — vratio je HTTP 200 sa praznim data. Najcesce je kriv oblik parametra, '
            . 'na primer pun ISO timestamp tamo gde se prima samo YYYY-MM-DD.',
            200
        );
    }

    public static function notJson(string $path, string $snippet): self
    {
        return new self(
            "Odgovor na {$path} nije JSON. Pocetak odgovora: " . mb_substr($snippet, 0, 120)
        );
    }

    /**
     * Stranicenje nije prihvaceno: trazena je strana N, server vratio drugu.
     *
     * Posebna greska jer AssetOPS pogresno ime parametra ne prijavljuje —
     * tiho vrati prvu stranu. Bez ove provere bi se od 36 zapisa procitalo
     * 25, bez ijednog znaka da nesto nije u redu.
     */
    public static function paginationIgnored(string $path, int $wanted, int $got): self
    {
        return new self(
            "AssetOPS na {$path} nije prihvatio stranicenje: trazena je strana {$wanted}, "
            . "vracena {$got}. Citanje je prekinuto da se nepotpun spisak ne bi uzeo kao potpun."
        );
    }

    public static function incomplete(string $path, int $read, int $total): self
    {
        return new self(
            "Sa {$path} je procitano {$read} od {$total} zapisa. Nepotpuno citanje se ne "
            . 'koristi, jer zapisi koji nedostaju izgledaju kao zapisi koji su obrisani.'
        );
    }

    public static function unexpected(int $status, string $path, string $detail = ''): self
    {
        return new self(
            "AssetOPS je na {$path} vratio HTTP {$status}" . ($detail === '' ? '' : ": {$detail}"),
            $status
        );
    }
}
