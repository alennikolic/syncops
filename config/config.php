<?php
// config/config.php
//
// Jedino mesto gde se citaju env promenljive. Ostatak aplikacije dobija
// konfiguraciju kao niz i nikada ne zove getenv() direktno.

declare(strict_types=1);

/**
 * @return array{
 *     env: string,
 *     db: array{host: string, port: int, name: string, user: string, password: string},
 *     secret_key: string,
 *     paths: array{root: string, migrations: string}
 * }
 */

$root = dirname(__DIR__);

/** Procita env promenljivu, sa podrazumevanom vrednoscu. */
$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
};

return [
    'env' => $env('APP_ENV', 'dev'),

    'db' => [
        'host'     => $env('DB_HOST', 'db'),
        'port'     => (int) $env('DB_PORT', '3306'),
        'name'     => $env('DB_NAME'),
        'user'     => $env('DB_USER'),
        'password' => $env('DB_PASSWORD'),
    ],

    // Kljuc za enkripciju kredencijala. Ne koristi se u ovoj fazi,
    // ali se ucitava da bi "migrate" mogao da upozori ako nedostaje.
    'secret_key' => $env('SECRET_KEY'),

    'paths' => [
        'root'       => $root,
        'migrations' => $root . '/db/migrations',
    ],
];
