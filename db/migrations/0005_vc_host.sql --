-- db/migrations/0005_vc_host.sql
--
-- Inventory sloj: ESXi hostovi.
--
-- VAZNO: REST API za hostove daje samo name, connection_state i power_state.
-- Sve hardverske kolone nize (vendor, model, serial_number, CPU, RAM, ESXi
-- build) ostaju NULL dok se ne doda SOAP klijent. Postoje od pocetka zato
-- sto su one razlog zbog kojeg host uopste postaje CI u CMDB-u — da dodavanje
-- SOAP-a bude promena drajvera, a ne migracija seme nad punom tabelom.
--
-- Kolona hardware_source belezi odakle podaci poticu, da se u GUI-ju vidi
-- razlika izmedju "nije prikupljeno" i "prikupljeno pa prazno".
--
-- Napomena o power_state: REST ga ne vraca kada je host u stanju
-- NOT_RESPONDING (struktura ima union constraint), pa kolona mora biti NULL.

CREATE TABLE vc_host (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(64)       NOT NULL COMMENT 'npr. host-28',
    name             VARCHAR(255)      NOT NULL COMMENT 'FQDN kako ga vidi vCenter',

    connection_state ENUM('CONNECTED','DISCONNECTED','NOT_RESPONDING') DEFAULT NULL,
    power_state      ENUM('POWERED_ON','POWERED_OFF','STANDBY') DEFAULT NULL
                     COMMENT 'REST ga izostavlja kada je host NOT_RESPONDING',

    cluster_moref    VARCHAR(64)       DEFAULT NULL,
    datacenter_moref VARCHAR(64)       DEFAULT NULL,

    -- --- Hardver: popunjava SOAP klijent, do tada NULL -------------------
    hardware_source  ENUM('rest','soap') DEFAULT NULL
                     COMMENT 'NULL = hardverski podaci jos nisu prikupljeni',

    vendor           VARCHAR(100)      DEFAULT NULL,
    model            VARCHAR(150)      DEFAULT NULL,
    serial_number    VARCHAR(100)      DEFAULT NULL COMMENT 'Kljucno za CMDB',
    asset_tag        VARCHAR(100)      DEFAULT NULL,

    cpu_model        VARCHAR(150)      DEFAULT NULL,
    cpu_sockets      SMALLINT UNSIGNED DEFAULT NULL,
    cpu_cores        SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Ukupno jezgara',
    cpu_threads      SMALLINT UNSIGNED DEFAULT NULL,
    cpu_mhz          INT UNSIGNED      DEFAULT NULL,

    memory_bytes     BIGINT UNSIGNED   DEFAULT NULL,

    esxi_version     VARCHAR(32)       DEFAULT NULL COMMENT 'npr. 8.0.2',
    esxi_build       VARCHAR(32)       DEFAULT NULL,
    bios_version     VARCHAR(64)       DEFAULT NULL,
    hardware_uuid    CHAR(36)          DEFAULT NULL,
    -- ---------------------------------------------------------------------

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_host (integration_id, moref),
    KEY idx_host_last_seen (integration_id, last_seen),
    KEY idx_host_name (integration_id, name),
    KEY idx_host_cluster (integration_id, cluster_moref),

    -- Za kasniju korelaciju izmedju vCentera i sa drugim izvorima.
    -- Nije UNIQUE: serijski broj ume da bude prazan ili netacno prijavljen.
    KEY idx_host_serial (serial_number),
    KEY idx_host_hw_uuid (hardware_uuid),

    CONSTRAINT fk_host_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: ESXi hostovi, trenutno stanje';


CREATE TABLE vc_host_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    host_id    INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_host_change (host_id, changed_at),
    KEY idx_host_change_run (run_id),

    CONSTRAINT fk_host_change_host
        FOREIGN KEY (host_id) REFERENCES vc_host (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_host_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad hostovima';
