-- db/migrations/0013_assetops_field_map.sql
--
-- Cime se puni koje polje CI-ja u AssetOPS-u, i sme li SyncOPS da ga prepise.
--
-- Dve stvari se ovde resavaju:
--
--  1. KLJUC SPAJANJA. SyncOPS mora da zna koji CI u AssetOPS-u odgovara kojoj
--     masini iz vCentera. Ime se menja, serijski broj kod masina ne postoji,
--     pa se u AssetOPS upisuje instance_uuid — u EAV atribut, jer nativnog
--     polja za njega nema. Kod hostova je to hardware_uuid.
--
--     Taj atribut NIJE dovoljan sam: AssetOPS ne ume da filtrira po EAV
--     vrednosti (probana tri oblika, svi se ignorisu), pa se spajanje radi
--     citanjem svih CI-jeva i poredjenjem lokalno. Atribut je trajno sidro
--     ako se lokalna baza izgubi; brzi put je tabela veze, koja dolazi sa
--     slojem sinhronizacije.
--
--  2. VLASNISTVO NAD POLJEM. Bez ovoga bi prva sinhronizacija pregazila ono
--     sto su ljudi rucno uneli, i vise niko ne bi hteo da je ukljuci. Zato je
--     podrazumevano pravilo "ne diraj", a sta se upisuje bira se po polju.
--
-- Cilj je ili nativno polje CI-ja (name, serial_number...) ili EAV atribut,
-- koji se adresira slugom. Zato target_kind i target_key, a ne dve tabele.

CREATE TABLE ao_field_map (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    source_kind    ENUM('vm','host') NOT NULL
                   COMMENT 'Sta se preslikava: masina ili host iz vCentera',

    target_kind    ENUM('native','eav') NOT NULL,
    target_key     VARCHAR(190)      NOT NULL
                   COMMENT 'Ime nativnog polja ili slug EAV atributa',

    source_field   VARCHAR(64)       DEFAULT NULL
                   COMMENT 'Polje iz vCenter inventara; NULL uz konstantu',
    constant_value TEXT              DEFAULT NULL
                   COMMENT 'Fiksna vrednost, kada se ne preuzima iz vCentera',

    -- never      ne dira se (podrazumevano)
    -- create_only upisuje se samo pri kreiranju CI-ja
    -- always     prepisuje se pri svakoj sinhronizaciji
    -- if_empty   upisuje se samo ako je u AssetOPS-u prazno
    write_policy   ENUM('never','create_only','always','if_empty') NOT NULL DEFAULT 'never',

    -- Tacno jedan red po (integraciji, vrsti izvora) sme da bude kljuc.
    -- Jedinstveni indeks to ne moze da iskaze, pa pravilo cuva kod.
    is_correlation TINYINT(1)        NOT NULL DEFAULT 0,

    updated_at     DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    UNIQUE KEY uq_ao_field_map (integration_id, source_kind, target_kind, target_key),
    KEY idx_ao_field_map_corr (integration_id, source_kind, is_correlation),

    CONSTRAINT fk_ao_field_map_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Cime se puni polje CI-ja i sme li da se prepise';


-- Definicije EAV atributa postaju sesta vrsta referentnih podataka.
--
-- Citaju se sa /api/eav/attributes/, ne iz eav_fields u odgovoru liste:
-- eav_fields je nepouzdan u oba smera — izostavlja polja koja API vraca, a
-- kod virtual-assets je bio prazan iako su tri atributa vezana za taj entitet.
--
-- Atribut je vezan za ENTITET (virtual-asset, asset), ne za tip CI-ja. Zato
-- se spisak atributa ne menja kada se promeni izabrani tip.
ALTER TABLE ao_reference
    MODIFY kind ENUM('location','status','visibility_group',
                     'asset_type','virtual_asset_type','eav_attribute') NOT NULL;
