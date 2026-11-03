<?php
// src/Web/Flash.php
//
// Poruka koja prezivi jedno preusmerenje i zatim nestane. Koristi se posle
// upisa, da korisnik na sledecoj stranici vidi sta se desilo.

declare(strict_types=1);

namespace SyncOps\Web;

final class Flash
{
    private const KEY = 'syncops_flash';

    public function success(string $message): void
    {
        $_SESSION[self::KEY] = ['type' => 'success', 'message' => $message];
    }

    public function error(string $message): void
    {
        $_SESSION[self::KEY] = ['type' => 'error', 'message' => $message];
    }

    /**
     * Procitaj poruku i obrisi je.
     *
     * @return array{type: string, message: string}|null
     */
    public function take(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;

        unset($_SESSION[self::KEY]);

        return is_array($flash) ? $flash : null;
    }
}
