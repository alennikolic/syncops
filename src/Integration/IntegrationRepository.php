<?php
// src/Integration/IntegrationRepository.php
//
// Citanje i upis definicija integracija.
//
// Lozinka se namerno ne vraca uz objekat Integration — za nju postoji zaseban
// poziv password(), koji je desifruje tek kada klijent treba da se prijavi.

declare(strict_types=1);

namespace SyncOps\Integration;

use PDO;
use RuntimeException;
use SensitiveParameter;
use SyncOps\Core\Secret;

final class IntegrationRepository
{
    private const COLUMNS = 'id, type, name, hostname, port, username, api_flavor,
                             api_flavor_detected, product_version, verify_peer,
                             is_active, last_tested_at, last_test_ok,
                             last_test_message, created_at, updated_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Secret $secret,
    ) {
    }

    // -----------------------------------------------------------------
    // Citanje
    // -----------------------------------------------------------------

    /**
     * @return list<Integration>
     */
    public function all(bool $onlyActive = false, ?string $type = null): array
    {
        $sql    = 'SELECT ' . self::COLUMNS . ' FROM integration WHERE 1 = 1';
        $params = [];

        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }

        if ($type !== null) {
            $sql           .= ' AND type = :type';
            $params['type'] = $type;
        }

        $sql .= ' ORDER BY name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row): Integration => Integration::fromRow($row),
            $stmt->fetchAll()
        );
    }

    public function find(int $id): ?Integration
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM integration WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : Integration::fromRow($row);
    }

    public function findByEndpoint(string $type, string $hostname, int $port): ?Integration
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM integration
             WHERE type = :type AND hostname = :hostname AND port = :port'
        );
        $stmt->execute(['type' => $type, 'hostname' => $hostname, 'port' => $port]);

        $row = $stmt->fetch();

        return $row === false ? null : Integration::fromRow($row);
    }

    /**
     * Desifrovana lozinka. Poziva se iskljucivo u trenutku prijave na API.
     */
    public function password(int $id): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT password_encrypted FROM integration WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $blob = $stmt->fetchColumn();

        if ($blob === false) {
            throw new RuntimeException("Integracija {$id} ne postoji.");
        }

        return $this->secret->decrypt((string) $blob);
    }

    // -----------------------------------------------------------------
    // Upis
    // -----------------------------------------------------------------

    /**
     * @return int ID nove integracije
     */
    public function create(
        string $name,
        string $hostname,
        string $username,
        #[SensitiveParameter] string $password,
        int $port = 443,
        string $type = 'vcenter',
        string $apiFlavor = Integration::FLAVOR_SOAP,
        bool $verifyPeer = false,
    ): int {
        if ($this->findByEndpoint($type, $hostname, $port) !== null) {
            throw new RuntimeException(
                "Integracija za {$hostname}:{$port} vec postoji."
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO integration
                (type, name, hostname, port, username, password_encrypted,
                 api_flavor, verify_peer, is_active, created_at, updated_at)
             VALUES
                (:type, :name, :hostname, :port, :username, :password,
                 :api_flavor, :verify_peer, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        $stmt->bindValue('type', $type);
        $stmt->bindValue('name', $name);
        $stmt->bindValue('hostname', $hostname);
        $stmt->bindValue('port', $port, PDO::PARAM_INT);
        $stmt->bindValue('username', $username);
        $stmt->bindValue('password', $this->secret->encrypt($password), PDO::PARAM_LOB);
        $stmt->bindValue('api_flavor', $apiFlavor);
        $stmt->bindValue('verify_peer', $verifyPeer ? 1 : 0, PDO::PARAM_INT);

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Izmeni sve osim lozinke (za nju postoji updatePassword()).
     */
    public function update(
        int $id,
        string $name,
        string $hostname,
        int $port,
        string $username,
        string $apiFlavor,
        bool $verifyPeer,
    ): void {
        $existing = $this->findByEndpoint('vcenter', $hostname, $port);

        if ($existing !== null && $existing->id !== $id) {
            throw new RuntimeException(
                "Druga integracija vec koristi {$hostname}:{$port}."
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET name        = :name,
                    hostname    = :hostname,
                    port        = :port,
                    username    = :username,
                    api_flavor  = :api_flavor,
                    verify_peer = :verify_peer,
                    updated_at  = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->bindValue('name', $name);
        $stmt->bindValue('hostname', $hostname);
        $stmt->bindValue('port', $port, PDO::PARAM_INT);
        $stmt->bindValue('username', $username);
        $stmt->bindValue('api_flavor', $apiFlavor);
        $stmt->bindValue('verify_peer', $verifyPeer ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);

        $stmt->execute();
    }

    public function updatePassword(int $id, #[SensitiveParameter] string $password): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET password_encrypted = :password, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->bindValue('password', $this->secret->encrypt($password), PDO::PARAM_LOB);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET is_active = :active, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /**
     * Zabelezi ishod provere konekcije i, ako je detekcija uspela,
     * zapamti dijalekt i verziju proizvoda.
     */
    public function recordTest(
        int $id,
        bool $ok,
        ?string $message = null,
        ?string $detectedFlavor = null,
        ?string $productVersion = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET last_tested_at      = UTC_TIMESTAMP(),
                    last_test_ok        = :ok,
                    last_test_message   = :message,
                    api_flavor_detected = COALESCE(:flavor, api_flavor_detected),
                    product_version     = COALESCE(:version, product_version),
                    updated_at          = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->execute([
            'ok'      => $ok ? 1 : 0,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'flavor'  => $detectedFlavor,
            'version' => $productVersion,
            'id'      => $id,
        ]);
    }
}
