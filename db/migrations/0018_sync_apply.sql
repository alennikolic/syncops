-- db/migrations/0018_sync_apply.sql
--
-- Upis u AssetOPS: sta je poslato i sta se od toga desilo.
--
-- Dve stvari koje faza upisa trazi, a do sada nisu postojale.
--
-- 1. STA SMO POSLEDNJI PUT POSLALI
--
-- cmdb_link je do sada imao samo pushed_hash. Hes odgovara na pitanje „da li
-- se nesto promenilo", ali ne i na „sta". A pitanje koje se zaista postavlja
-- je po polju: da li je covek rucno ispravio bas ovo polje posle naseg
-- upisa. Zato se pamti celo poslato telo, ne otisak.
--
-- 2. DNEVNIK UPISA
--
-- Kada program napravi 156 zapisa u tudjem produkcionom CMDB-u, mora da
-- postoji spisak sta je tacno uradio — i to spisak koji prezivi i kada upis
-- padne na pola. Bez toga se posle greske ne zna ni sta je proslo ni sta
-- treba ponoviti.
--
-- Dnevnik je zapis o ucinjenom, ne red cekanja: red nastaje tek kad se
-- poziv posalje. Predlog zivi na ekranu i racuna se iznova; cuva se samo
-- ono sto se stvarno dogodilo.

ALTER TABLE cmdb_link
    ADD COLUMN pushed_payload MEDIUMTEXT DEFAULT NULL
        COMMENT 'Telo poslednjeg uspesnog upisa, za poredjenje po polju'
        AFTER pushed_hash;


CREATE TABLE sync_action (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL COMMENT 'AssetOPS integracija',

    source_kind    ENUM('vm','host') NOT NULL,
    source_id      BIGINT UNSIGNED   NOT NULL COMMENT 'vc_vm.id ili vc_host.id',
    source_name    VARCHAR(255)      NOT NULL
                   COMMENT 'Ime u trenutku upisa; objekat moze kasnije nestati',

    ci_uuid        CHAR(36)          DEFAULT NULL COMMENT 'NULL ako kreiranje nije uspelo',

    action         ENUM('create','update') NOT NULL,

    -- applied  poziv je prosao
    -- failed   poziv je odbijen ili pukao
    -- skipped  pred sam upis se ispostavilo da izmena vise nije potrebna
    status         ENUM('applied','failed','skipped') NOT NULL,

    http_status    SMALLINT UNSIGNED DEFAULT NULL,
    message        TEXT              DEFAULT NULL,

    -- Telo kakvo je poslato. Kod neuspeha je ovo jedino mesto na kom se vidi
    -- sta je tacno otislo — poruka servera cesto ne kaze.
    payload        MEDIUMTEXT        DEFAULT NULL,

    created_at     DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    KEY idx_sync_action_recent (integration_id, created_at),
    KEY idx_sync_action_source (integration_id, source_kind, source_id),

    CONSTRAINT fk_sync_action_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Dnevnik upisa u AssetOPS';
