<?php
// src/Core/Secret.php
//
// Enkripcija kredencijala koji se cuvaju u bazi (libsodium secretbox:
// XSalsa20-Poly1305, autentifikovana enkripcija).
//
// Format zapisa: nonce (24 bajta) + ciphertext, kao binarni blob u koloni
// VARBINARY. Nonce se generise nasumicno za svaki upis i cuva uz sam zapis —
// nije tajna, ali ne sme da se ponovi.
//
// Autentifikacija je deo algoritma: ako je zapis u bazi izmenjen makar za
// jedan bit, decrypt() baca izuzetak umesto da vrati besmislicu.
//
// Kljuc dolazi iz SECRET_KEY (32 bajta, base64). Ako se izgubi ili promeni,
// postojeci zapisi se ne mogu desifrovati — nema zaobilaznice, to je poenta.

declare(strict_types=1);

namespace SyncOps\Core;

use RuntimeException;
use SensitiveParameter;

final class Secret
{
    private string $key;

    public function __construct(#[SensitiveParameter] string $base64Key)
    {
        if ($base64Key === '') {
            throw new RuntimeException(
                'SECRET_KEY nije podesen. Generisi ga sa: '
                . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        $key = base64_decode($base64Key, true);

        if ($key === false) {
            throw new RuntimeException('SECRET_KEY nije ispravan base64 zapis.');
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(sprintf(
                'SECRET_KEY mora biti %d bajta (dobijeno %d). Generisi nov kljuc.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key)
            ));
        }

        $this->key = $key;
    }

    /**
     * Enkriptuj tekst. Vraca binarni blob: nonce + ciphertext.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    /**
     * Desifruj blob koji je napravio encrypt().
     *
     * @throws RuntimeException ako je zapis izmenjen ili je kljuc pogresan
     */
    public function decrypt(#[SensitiveParameter] string $blob): string
    {
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($blob) <= $nonceLength) {
            throw new RuntimeException('Enkriptovani zapis je prekratak ili ostecen.');
        }

        $nonce      = substr($blob, 0, $nonceLength);
        $ciphertext = substr($blob, $nonceLength);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException(
                'Desifrovanje nije uspelo. Ili je SECRET_KEY promenjen, '
                . 'ili je zapis u bazi ostecen.'
            );
        }

        return $plaintext;
    }

    /**
     * Novi nasumicni kljuc, spreman za upis u SECRET_KEY.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Brise kljuc iz memorije kada objekat nestane, da ne ostane u core dump-u
     * ili u ispisu greske.
     */
    public function __destruct()
    {
        if (isset($this->key)) {
            sodium_memzero($this->key);
        }
    }

    /** Kljuc nikada ne sme da zavrsi u var_dump-u ili u logu. */
    public function __debugInfo(): array
    {
        return ['key' => '[sakriveno]'];
    }
}
