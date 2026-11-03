-- db/migrations/0001_integration.sql
--
-- Definicije integracija: po jedan red za svaki izvor sa kojeg se prikupljaju
-- podaci. U ovoj fazi je to iskljucivo vCenter, ali kolona "type" postoji od
-- pocetka da bi se kasniji izvori dodavali bez menjanja seme.
--
-- Kredencijali se cuvaju enkriptovano (libsodium secretbox), kljucem iz
-- SECRET_KEY. Baza nikada ne vidi lozinku u citljivom obliku.

CREATE TABLE integration (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

    type                VARCHAR(32)       NOT NULL DEFAULT 'vcenter'
                        COMMENT 'vcenter, kasnije ksc, cmdb ...',
    name                VARCHAR(100)      NOT NULL
                        COMMENT 'Prikazno ime u GUI-ju',

    hostname            VARCHAR(255)      NOT NULL
                        COMMENT 'FQDN ili IP; prirodni kljuc integracije',
    port                SMALLINT UNSIGNED NOT NULL DEFAULT 443,

    username            VARCHAR(255)      NOT NULL,
    password_encrypted  VARBINARY(1024)   NOT NULL
                        COMMENT 'sodium secretbox: nonce + ciphertext',

    -- Dijalekt REST API-ja. "auto" znaci da ga SyncOPS detektuje sam pri
    -- prvom povezivanju i upisuje rezultat u api_flavor_detected.
    api_flavor          ENUM('auto','rest_legacy','api_v2') NOT NULL DEFAULT 'auto'
                        COMMENT 'rest_legacy = /rest (6.7-7.0), api_v2 = /api (7.0U2+)',
    api_flavor_detected ENUM('rest_legacy','api_v2') DEFAULT NULL,
    product_version     VARCHAR(50)       DEFAULT NULL
                        COMMENT 'Verzija vCentera, radi dijagnostike',

    -- U praksi su vCenteri cesto sa self-signed sertifikatom, pa je provera
    -- iskljucena po defaultu. Ukljucuje se po integraciji, svesno.
    verify_peer         TINYINT(1)        NOT NULL DEFAULT 0,

    is_active           TINYINT(1)        NOT NULL DEFAULT 1
                        COMMENT 'Neaktivna integracija se preskace pri discover --all',

    last_tested_at      DATETIME          DEFAULT NULL COMMENT 'UTC',
    last_test_ok        TINYINT(1)        DEFAULT NULL,
    last_test_message   VARCHAR(500)      DEFAULT NULL,

    created_at          DATETIME          NOT NULL COMMENT 'UTC',
    updated_at          DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_endpoint (type, hostname, port),
    KEY idx_integration_active (is_active, type)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Izvori sa kojih SyncOPS prikuplja podatke';
