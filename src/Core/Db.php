<?php
// src/Core/Db.php
//
// Tanak omotac oko PDO. Jedino mesto gde se pravi konekcija ka bazi.

declare(strict_types=1);

namespace SyncOps\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Db
{
    /**
     * Napravi PDO konekciju iz konfiguracionog niza.
     *
     * @param array{host: string, port: int, name: string, user: string, password: string} $config
     * @param int $retries Broj ponovnih pokusaja (MySQL kontejner zna da
     *                     jos uvek ne prihvata konekcije kad PHP krene).
     */
    public static function connect(array $config, int $retries = 5): PDO
    {
        if ($config['name'] === '') {
            throw new RuntimeException('DB_NAME nije podesen. Proveri .env fajl.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['name']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        $lastError = null;

        for ($attempt = 1; $attempt <= max(1, $retries); $attempt++) {
            try {
                $pdo = new PDO($dsn, $config['user'], $config['password'], $options);

                // Sve vreme u bazi je UTC, bez obzira na podesavanja servera.
                $pdo->exec("SET time_zone = '+00:00'");

                return $pdo;
            } catch (PDOException $e) {
                $lastError = $e;

                if ($attempt < $retries) {
                    sleep(2);
                }
            }
        }

        throw new RuntimeException(
            sprintf(
                'Konekcija ka bazi nije uspela posle %d pokusaja: %s',
                $retries,
                $lastError?->getMessage() ?? 'nepoznata greska'
            ),
            0,
            $lastError
        );
    }
}
