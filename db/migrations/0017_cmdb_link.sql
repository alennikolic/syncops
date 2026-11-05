-- db/migrations/0017_cmdb_link.sql
--
-- Koji objekat iz vCentera odgovara kom CI-ju u AssetOPS-u.
--
-- Ovo je tabela na kojoj stoji sve ostalo. Dok veze nema, SyncOPS ne zna da
-- li masinu treba kreirati ili azurirati, pa bi svaki prolaz pravio nove
-- CI-jeve pored postojecih.
--
-- DVA JEDINSTVENA INDEKSA, NE JEDAN.
--
-- uq_cmdb_link_source kaze da jedan objekat iz vCentera ima najvise jedan
-- CI. uq_cmdb_link_ci kaze i obrnuto: jedan CI pripada najvise jednom
-- objektu. Drugi je vazniji i lakse se previdi — bez njega bi dve masine
-- mogle da pisu u isti CI i tiho se gaze.
--
-- Baza to drzi kao pravilo, a ne kod: greska u spajanju tada pukne pri
-- upisu, umesto da se otkrije kroz podatke koji se ne slazu.
--
-- NEMA STRANOG KLJUCA KA INVENTARU.
--
-- source_id pokazuje u vc_vm ili vc_host, zavisno od source_kind. Strani
-- kljuc ne moze da bira tabelu, pa se veza ka nestalom objektu otkriva
-- citanjem — i to je u redu: objekat koji nestane iz vCentera ne sme da
-- povuce vezu sa sobom, jer faza nestanka tek treba da odluci sta sa CI-jem.

CREATE TABLE cmdb_link (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL COMMENT 'AssetOPS integracija',

    source_kind    ENUM('vm','host') NOT NULL,
    source_id      BIGINT UNSIGNED   NOT NULL COMMENT 'vc_vm.id ili vc_host.id',

    ci_uuid        CHAR(36)          NOT NULL COMMENT 'uuid CI-ja u AssetOPS-u',

    -- Cime je veza napravljena. Nije isto koliko se kojoj veruje:
    --   correlation  vrednost kljuca spajanja se poklopila — jedino pouzdano
    --   name         isto ime; ime se menja i nije jedinstveno
    --   serial       isti serijski broj; na testnoj instanci 14 od 17 masina
    --                deli istu vrednost, pa ni ovo nije kljuc samo po sebi
    --   manual       covek je rekao
    matched_by     ENUM('correlation','name','serial','manual') NOT NULL,

    -- Slaba veza (name, serial) ne sme da upisuje dok je covek ne potvrdi.
    -- Jaka (correlation) i rucna su potvrdjene po nastanku.
    confirmed      TINYINT(1)        NOT NULL DEFAULT 0,

    -- Hes onoga sto je SyncOPS poslednji put poslao. Treca tacka poredjenja,
    -- uz vCenter sada i AssetOPS sada: bez nje se ne razlikuje "vCenter se
    -- promenio" od "covek je ispravio u AssetOPS-u". Puni se u fazi upisa.
    pushed_hash    CHAR(64)          DEFAULT NULL,
    pushed_at      DATETIME          DEFAULT NULL COMMENT 'UTC',

    linked_at      DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    UNIQUE KEY uq_cmdb_link_source (integration_id, source_kind, source_id),
    UNIQUE KEY uq_cmdb_link_ci (integration_id, ci_uuid),

    CONSTRAINT fk_cmdb_link_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Veza izmedju vCenter objekta i CI-ja u AssetOPS-u';
