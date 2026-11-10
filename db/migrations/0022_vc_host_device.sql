-- db/migrations/0022_vc_host_device.sql
--
-- Inventory sloj: uredjaji hosta — fizicke mrezne kartice, VMkernel adapteri,
-- standardni svicevi, port grupe i HBA.
--
-- JEDNA TABELA ZA PET VRSTA. Svaka vrsta nosi svoja polja, pa je ovo unija
-- kolona u kojoj je vecina NULL u svakom pojedinacnom redu. Alternativa je
-- pet tabela sa pet journala, za podatke koji se uvek gledaju zajedno, na
-- istoj stranici hosta. Kolona kind kaze koja je vrsta u pitanju.
--
-- IDENTITET SE SKLAPA. Ovi uredjaji nisu objekti inventara i nemaju moref —
-- postoje samo kao stavke u konfiguraciji hosta. Kljuc je host + vrsta +
-- ime uredjaja, npr. "host-9072377:pnic:vmnic0". Mereno na stvarnom
-- odgovoru: imena su jedinstvena u okviru hosta, pa ovo drzi. Vrsta je u
-- kljucu jer se ime uredjaja i ime port grupe mogu poklopiti.
--
-- ZASTO ZASEBAN PROLAZ. Ova polja se NE dodaju u postojece citanje hostova.
-- Mereno: odgovor za pet hostova raste sa 1,1 KB na 291 KB, dakle 255 puta.
-- HostCollector cita hostove vise puta (puna lista plus jednom po datacentru,
-- kesirano po kontejneru), pa bi se taj trosak mnozio. Ovako je jedan
-- dodatni prolaz, tri poziva, i 291 KB tacno jednom po prikupljanju.
--
-- Najveci deo tih 291 KB je iSCSI HBA: nosi supportedAdvancedOptions i
-- advancedOptions, nekoliko desetina blokova po adapteru. Nista od toga se
-- ne cuva.
--
-- WWN se cuva heksadecimalno. vCenter FC adapter javlja decimalni broj
-- (2378169888378566912), a SAS vec heksadecimalni string. U bazi stoji
-- oblik koji se svuda i koristi: 21:00:f4:e9:d4:55:b9:00.

CREATE TABLE vc_host_device (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id      SMALLINT UNSIGNED NOT NULL,

    moref               VARCHAR(160)      NOT NULL
                        COMMENT 'Sklopljen kljuc: host:vrsta:uredjaj',

    host_moref          VARCHAR(64)       NOT NULL COMMENT 'Spaja se sa vc_host.moref',
    kind                VARCHAR(16)       NOT NULL COMMENT 'pnic, vmk, vswitch, portgroup, hba',
    device              VARCHAR(128)      NOT NULL COMMENT 'vmnic0, vmk0, vSwitch0, VLAN 1408, vmhba1',

    -- Mrezne kartice i VMkernel adapteri
    mac                 VARCHAR(32)       DEFAULT NULL,
    driver              VARCHAR(64)       DEFAULT NULL COMMENT 'pnic i hba',
    driver_version      VARCHAR(64)       DEFAULT NULL COMMENT 'samo pnic',
    firmware_version    VARCHAR(64)       DEFAULT NULL COMMENT 'samo pnic',
    speed_mb            INT UNSIGNED      DEFAULT NULL
                        COMMENT 'pnic: linkSpeed.speedMb; hba: brzina u Gb svedena na Mb. Izostaje kada je link dole',
    duplex              TINYINT(1)        DEFAULT NULL COMMENT 'samo pnic',
    pci                 VARCHAR(32)       DEFAULT NULL COMMENT 'pnic i hba',

    -- VMkernel adapteri
    ip_address          VARCHAR(45)       DEFAULT NULL COMMENT 'IPv4 ili IPv6',
    subnet_mask         VARCHAR(45)       DEFAULT NULL,
    gateway             VARCHAR(45)       DEFAULT NULL,
    dhcp                TINYINT(1)        DEFAULT NULL,
    mtu                 INT UNSIGNED      DEFAULT NULL COMMENT 'vmk i vswitch',
    port_group          VARCHAR(128)      DEFAULT NULL COMMENT 'Port grupa na kojoj vmk sedi',

    -- Standardni svicevi
    num_ports           INT UNSIGNED      DEFAULT NULL,
    num_ports_available INT UNSIGNED      DEFAULT NULL,

    -- Port grupe
    vlan_id             INT               DEFAULT NULL COMMENT '0 = bez VLAN-a, 4095 = trunk',
    vswitch_name        VARCHAR(128)      DEFAULT NULL,

    -- HBA
    hba_type            VARCHAR(32)       DEFAULT NULL
                        COMMENT 'fibreChannel, iscsi, serialAttached, parallelScsi',
    model               VARCHAR(255)      DEFAULT NULL,
    status              VARCHAR(32)       DEFAULT NULL COMMENT 'online, offline, unknown, fault',
    storage_protocol    VARCHAR(32)       DEFAULT NULL,
    wwpn                VARCHAR(32)       DEFAULT NULL COMMENT 'Heksadecimalno, sa dvotackama',
    wwnn                VARCHAR(32)       DEFAULT NULL COMMENT 'Heksadecimalno, sa dvotackama',
    iscsi_name          VARCHAR(255)      DEFAULT NULL COMMENT 'iqn.…',

    payload_hash        CHAR(64)          NOT NULL,

    first_seen          DATETIME          NOT NULL COMMENT 'UTC',
    last_seen           DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id        INT UNSIGNED      NOT NULL,
    last_run_id         INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_host_device (integration_id, moref),
    KEY idx_host_device_host (integration_id, host_moref, kind),
    KEY idx_host_device_kind (integration_id, kind),
    KEY idx_host_device_last_seen (integration_id, last_seen),

    CONSTRAINT fk_host_device_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: uredjaji hostova, trenutno stanje';


CREATE TABLE vc_host_device_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_host_device_change (device_id, changed_at),
    KEY idx_host_device_change_run (run_id),

    CONSTRAINT fk_host_device_change_device
        FOREIGN KEY (device_id) REFERENCES vc_host_device (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad uredjajima hostova';


-- Status uredjaja po run-u, uz ostale tipove.
ALTER TABLE discovery_run
    ADD COLUMN host_devices_status  ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending'
        AFTER datastores_status,
    ADD COLUMN host_devices_found   INT UNSIGNED NOT NULL DEFAULT 0 AFTER datastores_found,
    ADD COLUMN host_devices_changed INT UNSIGNED NOT NULL DEFAULT 0 AFTER datastores_changed;
