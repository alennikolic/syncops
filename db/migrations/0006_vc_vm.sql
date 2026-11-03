-- db/migrations/0006_vc_vm.sql
--
-- Inventory sloj: virtuelne masine.
--
-- Identifikatori su ovde najvazniji deo, jer od njih zavisi cela kasnija faza
-- korelacije:
--   instance_uuid  jedinstven u okviru jednog vCentera, stabilan -> primarni
--   bios_uuid      SMBIOS UUID koji vidi gost -> veza ka agentima na masinama;
--                  NIJE jedinstven (klonovi i restore umeju da ga ponove)
--   moref          vazi samo unutar vCentera i menja se pri re-registraciji;
--                  cuva se za dalje API pozive i dijagnostiku, ne kao kljuc
--
-- Oba UUID-a su nullable: identity blok postoji tek od vSphere API 6.7.1, a
-- dolazi iskljucivo iz detalja po VM-u, pa moze izostati.
--
-- Ako VM predje u drugi vCenter, ovde nastaje nov red (druga integracija).
-- Prepoznavanje da je rec o istoj masini radi kasnija obrada, nad ovim
-- identifikatorima.
--
-- RAZLIKA IZMEDJU DVA OPISA OPERATIVNOG SISTEMA:
--   guest_os         konfigurisan u vCenteru ("sta je korisnik izabrao")
--   guest_full_name  stvarno prijavljen iz gosta preko VMware Tools
-- Kod zapustenih masina razlika ume da bude velika i za CMDB je relevantna.

CREATE TABLE vc_vm (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    moref                VARCHAR(64)       NOT NULL COMMENT 'npr. vm-1234',
    instance_uuid        CHAR(36)          DEFAULT NULL COMMENT 'vCenter-specifican',
    bios_uuid            CHAR(36)          DEFAULT NULL COMMENT 'SMBIOS, nije jedinstven',

    name                 VARCHAR(255)      NOT NULL COMMENT 'Ime objekta u vCenteru',
    power_state          ENUM('POWERED_ON','POWERED_OFF','SUSPENDED') DEFAULT NULL,
    is_template          TINYINT(1)        NOT NULL DEFAULT 0,

    guest_os             VARCHAR(100)      DEFAULT NULL COMMENT 'Konfigurisan u vCenteru',
    hardware_version     VARCHAR(16)       DEFAULT NULL COMMENT 'npr. VMX_19',

    cpu_count            SMALLINT UNSIGNED DEFAULT NULL,
    cpu_cores_per_socket SMALLINT UNSIGNED DEFAULT NULL,
    memory_mib           INT UNSIGNED      DEFAULT NULL,

    disk_count           SMALLINT UNSIGNED DEFAULT NULL,
    disk_capacity_bytes  BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Zbir svih vDisk-ova',
    nic_count            SMALLINT UNSIGNED DEFAULT NULL,

    -- --- Podaci iz gosta -------------------------------------------------
    -- Izvor: GET /api/vcenter/vm/{vm}/guest/identity
    -- Dostupno samo dok VMware Tools rade i masina je upaljena. Kada nisu,
    -- API vraca gresku, pa se razlog belezi u guest_state umesto da polja
    -- ostanu tiho prazna.
    --
    -- NAPOMENA: ova polja ucestvuju u payload_hash-u, pa promena IP adrese
    -- pravi zapis u vc_vm_change. Kod DHCP okruzenja to moze biti bucno;
    -- ako postane problem, iskljucuju se iz hash-a (izmena u maperu, ne u semi).

    guest_state          ENUM('available','tools_not_running','powered_off','unavailable')
                         DEFAULT NULL
                         COMMENT 'Zasto podaci iz gosta jesu ili nisu prikupljeni',

    guest_hostname       VARCHAR(255)      DEFAULT NULL
                         COMMENT 'DNS ime kako ga prijavljuje gost',
    guest_ip_address     VARCHAR(45)       DEFAULT NULL
                         COMMENT 'Primarna adresa; 45 znakova zbog IPv6',
    guest_ip_addresses   JSON              DEFAULT NULL
                         COMMENT 'Sve adrese; popunjava se tek uz poziv guest/networking/interfaces',

    guest_os_family      VARCHAR(32)       DEFAULT NULL COMMENT 'WINDOWS, LINUX, OTHER',
    guest_full_name      VARCHAR(255)      DEFAULT NULL
                         COMMENT 'Stvarni OS prijavljen iz gosta',

    guest_seen_at        DATETIME          DEFAULT NULL
                         COMMENT 'UTC; kada su podaci iz gosta poslednji put uspesno procitani',
    -- ---------------------------------------------------------------------

    -- Smestaj. Cuva se kao moref, bez stranog kljuca: VM moze biti
    -- prikupljen u run-u u kojem hostovi nisu prosli, pa FK ne sme da obori
    -- upis. Relacije se grade u kasnijoj fazi.
    host_moref           VARCHAR(64)       DEFAULT NULL,
    cluster_moref        VARCHAR(64)       DEFAULT NULL,
    folder_moref         VARCHAR(64)       DEFAULT NULL,
    datacenter_moref     VARCHAR(64)       DEFAULT NULL,

    payload_hash         CHAR(64)          NOT NULL,

    first_seen           DATETIME          NOT NULL COMMENT 'UTC',
    last_seen            DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id         INT UNSIGNED      NOT NULL,
    last_run_id          INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_vm (integration_id, moref),

    -- instance_uuid je jedinstven unutar jednog vCentera, ali ne i globalno.
    UNIQUE KEY uq_vm_instance_uuid (integration_id, instance_uuid),

    -- bios_uuid NAMERNO nije UNIQUE — klonovi ga dele.
    KEY idx_vm_bios_uuid (bios_uuid),
    KEY idx_vm_instance_uuid (instance_uuid),

    -- Za korelaciju sa drugim izvorima (agenti, monitoring, mrezni skener)
    -- i za pretragu "cija je ovo adresa" u GUI-ju.
    KEY idx_vm_guest_hostname (guest_hostname),
    KEY idx_vm_guest_ip (guest_ip_address),

    KEY idx_vm_last_seen (integration_id, last_seen),
    KEY idx_vm_name (integration_id, name),
    KEY idx_vm_host (integration_id, host_moref),
    KEY idx_vm_cluster (integration_id, cluster_moref),

    CONSTRAINT fk_vm_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_vm_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_vm_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtuelne masine, trenutno stanje';


CREATE TABLE vc_vm_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vm_id      INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_vm_change (vm_id, changed_at),
    KEY idx_vm_change_run (run_id),

    CONSTRAINT fk_vm_change_vm
        FOREIGN KEY (vm_id) REFERENCES vc_vm (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vm_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad virtuelnim masinama';
