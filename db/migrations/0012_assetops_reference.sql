-- db/migrations/0012_assetops_reference.sql
--
-- Referentni podaci iz AssetOPS-a i mapiranje vCenter objekata na AssetOPS
-- tipove.
--
-- ao_reference: jedna tabela za pet vrsta spiskova umesto pet tabela.
-- Razlog: svi dele isti oblik — slug kao identitet, ime za prikaz, mesto u
-- hijerarhiji — a razlikuju se u nekoliko polja koja idu u payload. Sesta
-- vrsta se dodaje jednom vrednoscu u ENUM-u, bez nove tabele.
--
-- Identitet je SLUG, ne uuid. Lokacije uopste nemaju uuid, a i kod ostalih
-- se veza pri upisu salje kao slug. Slug se ne izvodi iz imena i prezivljava
-- preimenovanje: "Beograd" ima slug "beograd-3", "Magacin" ima
-- "sluzba-za-racunarske-mreze". Zato se nikad ne gradi iz imena.
--
-- Redovi se ne brisu. Kada neki slug nestane iz AssetOPS-a, njegov last_seen
-- prestane da prati ostale i prikaz ga oznaci — isto pravilo kao u inventaru.

CREATE TABLE ao_reference (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    kind           ENUM('location','status','visibility_group',
                        'asset_type','virtual_asset_type') NOT NULL,

    slug           VARCHAR(190)      NOT NULL COMMENT 'Identitet u AssetOPS-u',
    name           VARCHAR(255)      NOT NULL COMMENT 'Ime za prikaz',

    -- Lokacije i tipovi su hijerarhijski. depth i position cuvaju redosled
    -- obilaska stabla, da se spisak prikaze onako kako izgleda u AssetOPS-u
    -- bez ponovnog sklapanja stabla pri svakom prikazu.
    parent_slug    VARCHAR(190)      DEFAULT NULL,
    depth          TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    position       INT UNSIGNED      NOT NULL DEFAULT 0,

    color          VARCHAR(16)       DEFAULT NULL,
    payload        TEXT              NOT NULL COMMENT 'Ceo zapis kako je stigao',

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    UNIQUE KEY uq_ao_reference (integration_id, kind, slug),
    KEY idx_ao_reference_kind (integration_id, kind, position),

    CONSTRAINT fk_ao_reference_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Referentni spiskovi iz AssetOPS-a (lokacije, statusi, grupe, tipovi)';


-- ao_mapping: cime postaje vCenter objekat kada se upise u AssetOPS.
--
-- Dva reda po AssetOPS integraciji: jedan za virtuelne masine, jedan za
-- hostove. Nisu isti tip CI-ja — masina je virtual-asset, host je asset
-- (fizicka oprema sa serijskim brojem) — pa biraju iz razlicitih spiskova
-- tipova.
--
-- Lokacija i visibility grupa nisu ukras: CreateVirtualAsset trazi name,
-- location i visibility_groups, a CreateAsset jos i units_required. Bez njih
-- upis pada, pa se biraju unapred.
--
-- units_required je po znacenju broj rek jedinica, ali ga swagger navodi kao
-- obavezan, pa se salje sa podrazumevanom vrednoscu. Da li ga server stvarno
-- zahteva nije provereno — provericemo kada dodje upis.
--
-- Slugovi se NE vezuju stranim kljucem na ao_reference: mapiranje sme da
-- ostane podeseno i ako referentni spisak jos nije prikupljen, a i da
-- prezivi nestanak sluga iz AssetOPS-a, da se vidi sta je bilo podeseno.

CREATE TABLE ao_mapping (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    source_kind    ENUM('vm','host') NOT NULL
                   COMMENT 'Sta se preslikava: masina ili host iz vCentera',

    type_slug      VARCHAR(190)      DEFAULT NULL
                   COMMENT 'virtual_asset_type za vm, asset_type za host',
    location_slug  VARCHAR(190)      DEFAULT NULL,
    visibility_groups TEXT           DEFAULT NULL COMMENT 'JSON niz slugova',
    units_required SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Samo za host (asset)',

    updated_at     DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),
    UNIQUE KEY uq_ao_mapping (integration_id, source_kind),

    CONSTRAINT fk_ao_mapping_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Cime vCenter objekat postaje u AssetOPS-u';
