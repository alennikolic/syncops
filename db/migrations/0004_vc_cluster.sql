-- db/migrations/0004_vc_cluster.sql
--
-- Inventory sloj: klasteri.
--
-- Red se nikada ne brise. Ako klaster nestane iz vCentera, red ostaje a
-- last_seen prestaje da se pomera; zakljucak "obrisan je" donosi kasnija
-- obrada, uz proveru da li je run uopste bio kompletan.
--
-- payload_hash je SHA-256 normalizovanog JSON-a objekta. Ako je nepromenjen,
-- osvezavaju se samo last_seen i last_run_id. Ako se promenio, red se azurira
-- i upisuje se zapis u vc_cluster_change.
--
-- Sta REST daje za klaster: name, ha_enabled, drs_enabled. Nista vise.

CREATE TABLE vc_cluster (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(64)       NOT NULL COMMENT 'npr. domain-c123',
    name             VARCHAR(255)      NOT NULL,

    datacenter_moref VARCHAR(64)       DEFAULT NULL,
    datacenter_name  VARCHAR(255)      DEFAULT NULL,

    ha_enabled       TINYINT(1)        DEFAULT NULL,
    drs_enabled      TINYINT(1)        DEFAULT NULL,

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cluster (integration_id, moref),
    KEY idx_cluster_last_seen (integration_id, last_seen),
    KEY idx_cluster_name (integration_id, name),

    CONSTRAINT fk_cluster_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: klasteri, trenutno stanje';


CREATE TABLE vc_cluster_change (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cluster_id  INT UNSIGNED    NOT NULL,
    run_id      INT UNSIGNED    NOT NULL,
    changed_at  DATETIME        NOT NULL COMMENT 'UTC',
    old_values  JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values  JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_cluster_change (cluster_id, changed_at),
    KEY idx_cluster_change_run (run_id),

    CONSTRAINT fk_cluster_change_cluster
        FOREIGN KEY (cluster_id) REFERENCES vc_cluster (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad klasterima';
