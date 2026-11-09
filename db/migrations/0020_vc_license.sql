-- db/migrations/0020_vc_license.sql
--
-- Inventory sloj: licence sa vCentera.
--
-- Licenca NEMA moref. Ona nije objekat u stablu inventara nego stavka koju
-- drzi LicenseManager, jedan jedini objekat po vCenteru. Njen identitet je
-- licencni kljuc, pa kljuc stoji u koloni moref — tako red ulazi u isti
-- mehanizam upisa i journala koji vec rade masine, hostovi i klasteri, bez
-- posebne grane u InventoryRepository.
--
-- Kljuc se cuva ceo, onako kako ga vCenter vraca. Ima 29 znakova u pet grupa.
--
-- Sta vCenter daje a sta ume da izostane (mereno na stvarnom odgovoru):
--   total     0 znaci neograniceno; kod eval licence je 0
--   used      IZOSTAJE kod eval licence, otuda NULL
--   cost_unit IZOSTAJE kod eval licence; inace "server" ili "cpuPackage:32core"
--   expires_at ima samo deo licenci; vecina nema rok
--
-- Sto se NE cuva: spisak feature-a (pedesetak po licenci) i ugnezdjeni
-- LicenseInfo blokovi, po jedan za svaki FileVersion. Jedan odgovor za devet
-- licenci je preko pet hiljada redova XML-a, a raw_object upisuje payload
-- svaki run bez deduplikacije. Cuva se sazeta verzija.
--
-- FileVersion se takodje ne cuva: menja se sa svakom zakrpom i pravio bi
-- zapis u journalu bez vrednosti za evidenciju. ProductVersion (7.0, 8.0)
-- nosi ono sto je bitno.

CREATE TABLE vc_license (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id  SMALLINT UNSIGNED NOT NULL,

    moref           VARCHAR(64)       NOT NULL COMMENT 'licencni kljuc; licenca nema moref',
    name            VARCHAR(255)      NOT NULL COMMENT 'npr. vSphere 8 Enterprise Plus',

    edition_key     VARCHAR(128)      DEFAULT NULL COMMENT 'npr. esx.enterprisePlus.cpuPackageCoreLimited',
    product_name    VARCHAR(128)      DEFAULT NULL COMMENT 'npr. VMware ESX Server',
    product_version VARCHAR(32)       DEFAULT NULL COMMENT 'npr. 8.0',

    total           INT UNSIGNED      DEFAULT NULL COMMENT '0 = neograniceno',
    used            INT UNSIGNED      DEFAULT NULL COMMENT 'izostaje kod eval licence',
    cost_unit       VARCHAR(64)       DEFAULT NULL COMMENT 'server, cpuPackage:32core...',

    expires_at      DATETIME          DEFAULT NULL COMMENT 'UTC; vecina licenci nema rok',

    payload_hash    CHAR(64)          NOT NULL,

    first_seen      DATETIME          NOT NULL COMMENT 'UTC',
    last_seen       DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id    INT UNSIGNED      NOT NULL,
    last_run_id     INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_license (integration_id, moref),
    KEY idx_license_last_seen (integration_id, last_seen),
    KEY idx_license_name (integration_id, name),
    KEY idx_license_expires (integration_id, expires_at),

    CONSTRAINT fk_license_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_license_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_license_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: licence sa vCentera, trenutno stanje';


CREATE TABLE vc_license_change (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id  INT UNSIGNED    NOT NULL,
    run_id      INT UNSIGNED    NOT NULL,
    changed_at  DATETIME        NOT NULL COMMENT 'UTC',
    old_values  JSON            NOT NULL COMMENT 'Samo polja koja su se promenila',
    new_values  JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_license_change (license_id, changed_at),
    KEY idx_license_change_run (run_id),

    CONSTRAINT fk_license_change_license
        FOREIGN KEY (license_id) REFERENCES vc_license (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_license_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Journal promena nad licencama; used se menja kako se hostovi dodaju i sklanjaju';


-- Status licenci po run-u, uz ona tri koja vec postoje.
--
-- discovery_run nosi po jednu trojku kolona za svaki tip objekta, a
-- RunRepository::recordType() sastavlja ime kolone od imena tipa. Bez ovih
-- kolona upis statusa za licence bi gadjao nepostojecu kolonu.
--
-- 'pending' kao podrazumevano znaci i ono sto treba: run u kom licence nisu
-- ni pokusane (stariji run, ili nalog bez prava pa kolektor vrati praznu
-- listu) ostaje razlucen od onog u kom su procitane.
ALTER TABLE discovery_run
    ADD COLUMN licenses_status  ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending'
        COMMENT 'Licence umeju da izostanu kada nalogu fali Global.Licenses'
        AFTER vms_status,
    ADD COLUMN licenses_found   INT UNSIGNED NOT NULL DEFAULT 0 AFTER vms_found,
    ADD COLUMN licenses_changed INT UNSIGNED NOT NULL DEFAULT 0 AFTER vms_changed;
