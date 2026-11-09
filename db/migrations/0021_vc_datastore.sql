-- db/migrations/0021_vc_datastore.sql
--
-- Inventory sloj: datastore-ovi.
--
-- Za razliku od licenci, datastore JESTE objekat inventara i ima svoj moref,
-- pa se cita kroz ContainerView, isto kao hostovi i klasteri.
--
-- Sta vCenter daje a sta ume da izostane (mereno na stvarnom odgovoru od
-- petnaest datastore-ova):
--   uncommitted_bytes  IZOSTAJE tamo gde nema tanko dodeljenih diskova
--   maintenance_mode   VMFS ga ima; kod nekih tipova izostane
--   url, multiple_host_access, host_count  vSAN ume da ih ne vrati
--   vmfs_uuid/version  samo kod VMFS-a; kod NFS-a i vSAN-a ostaju NULL
--
-- free_bytes i uncommitted_bytes ULAZE u payload_hash, dakle i u journal.
-- To je svesna odluka: zauzece se menja izmedju svakog prikupljanja, pa
-- svaki run upisuje po jedan red za svaki datastore. Time se dobija istorija
-- zauzeca, a cena je sto kolona "izmenjeno" na stranici prikupljanja gotovo
-- uvek pokazuje pun broj datastore-ova.
--
-- BIGINT za bajtove: 10 TB je 10995116277760, sto INT UNSIGNED ne prima.
--
-- Sto se NE cuva iz info bloka: maxFileSize, maxVirtualDiskCapacity,
-- maxMemoryFileSize, maxPhysicalRDMFileSize, maxVirtualRDMFileSize i
-- timestamp. To su ogranicenja verzije VMFS-a, ista za sve datastore-ove iste
-- verzije, i duplikati name/url/freeSpace koji vec stoje u summary-ju. Cuva
-- se samo uuid, verzija i spisak ekstenata, i to u payload-u.

CREATE TABLE vc_datastore (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    moref                VARCHAR(64)       NOT NULL COMMENT 'npr. datastore-9077413',
    name                 VARCHAR(255)      NOT NULL,

    type                 VARCHAR(32)       DEFAULT NULL COMMENT 'VMFS, NFS, NFS41, vsan, VVOL',

    capacity_bytes       BIGINT UNSIGNED   DEFAULT NULL,
    free_bytes           BIGINT UNSIGNED   DEFAULT NULL,
    uncommitted_bytes    BIGINT UNSIGNED   DEFAULT NULL
                         COMMENT 'Tanko dodeljeno preko kapaciteta; izostane kada takvih diskova nema',

    is_accessible        TINYINT(1)        DEFAULT NULL
                         COMMENT 'accessible je rezervisana rec u MySQL-u, otuda prefiks',
    multiple_host_access TINYINT(1)        DEFAULT NULL,
    maintenance_mode     VARCHAR(32)       DEFAULT NULL COMMENT 'normal, enteringMaintenance, inMaintenance',
    overall_status       VARCHAR(16)       DEFAULT NULL COMMENT 'green, yellow, red, gray',

    url                  VARCHAR(255)      DEFAULT NULL,

    host_count           INT UNSIGNED      DEFAULT NULL COMMENT 'Koliko hostova ga montira; spisak je u payload-u',

    vmfs_uuid            VARCHAR(64)       DEFAULT NULL COMMENT 'Samo VMFS',
    vmfs_version         VARCHAR(16)       DEFAULT NULL COMMENT 'Samo VMFS, npr. 6.82',

    payload_hash         CHAR(64)          NOT NULL,

    first_seen           DATETIME          NOT NULL COMMENT 'UTC',
    last_seen            DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id         INT UNSIGNED      NOT NULL,
    last_run_id          INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_datastore (integration_id, moref),
    KEY idx_datastore_last_seen (integration_id, last_seen),
    KEY idx_datastore_name (integration_id, name),
    KEY idx_datastore_type (integration_id, type),

    CONSTRAINT fk_datastore_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: datastore-ovi, trenutno stanje';


CREATE TABLE vc_datastore_change (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    datastore_id  INT UNSIGNED    NOT NULL,
    run_id        INT UNSIGNED    NOT NULL,
    changed_at    DATETIME        NOT NULL COMMENT 'UTC',
    old_values    JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values    JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_datastore_change (datastore_id, changed_at),
    KEY idx_datastore_change_run (run_id),

    CONSTRAINT fk_datastore_change_datastore
        FOREIGN KEY (datastore_id) REFERENCES vc_datastore (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad datastore-ovima; zauzece se menja izmedju svakog prikupljanja';


-- Status datastore-ova po run-u, uz one koji vec postoje.
--
-- RunRepository::recordType() sastavlja ime kolone od imena tipa, pa bez ovih
-- kolona upis statusa gadja nepostojecu kolonu.
ALTER TABLE discovery_run
    ADD COLUMN datastores_status  ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending'
        AFTER licenses_status,
    ADD COLUMN datastores_found   INT UNSIGNED NOT NULL DEFAULT 0 AFTER licenses_found,
    ADD COLUMN datastores_changed INT UNSIGNED NOT NULL DEFAULT 0 AFTER licenses_changed;
