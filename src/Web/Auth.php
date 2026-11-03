<?php
// src/Web/Auth.php
//
// Prijava jednom lozinkom. Hash stoji u APP_PASSWORD_HASH, generise se sa:
//   bin/syncops password:hash
//
// Namerno nema tabele korisnika: za jedan tim koji upravlja sopstvenim
// vCenterima je to dovoljno, a prosirenje na vise naloga je kasnije nova
// migracija bez diranja kontrolera (oni samo pitaju check()).
//
// Ako hash nije podesen, aplikacija NE propusta nikoga — bolje je zatvoreno
// uz jasnu poruku nego tiho otvoreno.

declare(strict_types=1);

namespace SyncOps\Web;

use SensitiveParameter;

final class Auth
{
    private const SESSION_KEY = 'syncops_authenticated';
    private const CSRF_KEY    = 'syncops_csrf';

    public function __construct(
        #[SensitiveParameter] private readonly string $passwordHash,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true  — ukljuciti kada aplikacija bude isla preko HTTPS-a
        ]);

        session_name('syncops');
        session_start();
    }

    /** Da li je lozinka uopste podesena u okruzenju. */
    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function check(): bool
    {
        return ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    /**
     * Proveri lozinku i, ako je tacna, prijavi korisnika.
     */
    public function attempt(#[SensitiveParameter] string $password): bool
    {
        if (!$this->isConfigured() || $password === '') {
            return false;
        }

        if (!password_verify($password, $this->passwordHash)) {
            return false;
        }

        // Nov ID sesije posle prijave, protiv session fixation napada.
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = true;

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
            ]);
        }

        session_destroy();
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION[self::CSRF_KEY] ?? null;

        return is_string($expected)
            && is_string($token)
            && hash_equals($expected, $token);
    }

    /**
     * Hash spreman za upis u APP_PASSWORD_HASH.
     */
    public static function hashPassword(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
