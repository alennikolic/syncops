-- db/migrations/0016_ao_ci.sql
--
-- Sta je trenutno u AssetOPS-u. Cetvrti i poslednji deo slike pre nego sto
-- sinhronizacija moze da pocne: do sada su postojali inventar iz vCentera,
-- referentni spiskovi i mapiranje, ali ne i zateceno stanje odredista.
--
-- CITA SE CEO ENTITET, NE SAMO MAPIRANI TIP.
--
-- Mapiranje kaze koji je tip CI-ja za masine a koji za hostove, pa je
-- iskusenje da se cita filtrirano (?virtual_asset_type=<slug>, sto radi).
-- Ne radi se tako, iz dva razloga:
--
--   1. Nista se ne dobija. Ceo entitet je na demo instanci jedna strana sa
--      items-per-page=100. Filter bi usteeo nula poziva.
--
--   2. Promena tipa bi tiho napravila duplikat. Ako covek u AssetOPS-u
--      promeni tip CI-ja koji nosi nas kljuc spajanja, filtrirano citanje ga
--      ne vidi. SyncOPS ga tada ne prepozna kao postojeci i napravi novi CI
--      pored njega. Procitan ceo entitet, takav CI se umesto toga PRIJAVI
--      kao neusklada — vidi se u prikazu i resava se rukom.
--
-- Isto pravilo kao svuda u bazi: redovi se NE BRISU. CI koji nestane iz
-- AssetOPS-a ostaje sa starim last_seen i prikaz ga oznaci.

CREATE TABLE ao_ci (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    -- Entitet, ne tip. virtual-asset i asset su dva razlicita tipa CI-ja sa
    -- razlicitim poljima i razlicitim rutama, a ne jedan tip sa oznakom.
    entity         ENUM('virtual-asset','asset') NOT NULL,

    uuid           CHAR(36)          NOT NULL COMMENT 'Identitet u AssetOPS-u',
    name           VARCHAR(255)      NOT NULL,

    -- Izdvojeno iz payload-a da spajanje i prikaz ne raspakuju JSON po redu.
    -- Sve su to slugovi: veza sa ao_reference je slug, ne uuid.
    type_slug      VARCHAR(190)      DEFAULT NULL,
    status_slug    VARCHAR(190)      DEFAULT NULL,
    location_slug  VARCHAR(190)      DEFAULT NULL,
    serial_number  VARCHAR(255)      DEFAULT NULL,

    -- Vrednost kljuca spajanja, izvucena iz attribute_values po slugu koji
    -- mapiranje oznacava kao kljuc. correlation_key pamti IZ KOG atributa je
    -- uzeta: ako se kljuc u mapiranju promeni, stare vrednosti se prepoznaju
    -- kao zastarele umesto da se tiho porede sa novim.
    --
    -- Nije UNIQUE: duplikat je stanje koje treba PRIJAVITI, ne odbiti pri
    -- upisu. Dva CI-ja sa istim instance_uuid-om znace da je negde nastao
    -- duplikat, i to se vidi u prikazu.
    correlation_key   VARCHAR(190)   DEFAULT NULL,
    correlation_value VARCHAR(255)   DEFAULT NULL,

    -- Ceo zapis kako je stigao sa rute LISTE.
    --
    -- Kod assets detalji nisu nadskup liste: rack je pun u listi a plici u
    -- detaljima, status i tags obrnuto, a description postoji samo u
    -- detaljima. Lista je dovoljna za spajanje i za prikaz, jer nosi i
    -- attribute_values. Polje koje postoji samo u detaljima ne moze da se
    -- poredi iz ovoga — to resava faza poredjenja, dodatnim citanjem.
    payload        MEDIUMTEXT        NOT NULL,

    -- updated_at iz AssetOPS-a, ne nase vreme. Sluzi da se vidi da li je neko
    -- dirao CI od naseg poslednjeg upisa.
    remote_updated_at DATETIME       DEFAULT NULL COMMENT 'UTC, iz AssetOPS-a',

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    UNIQUE KEY uq_ao_ci (integration_id, uuid),
    KEY idx_ao_ci_corr (integration_id, entity, correlation_value),
    KEY idx_ao_ci_type (integration_id, entity, type_slug),
    KEY idx_ao_ci_seen (integration_id, last_seen),

    CONSTRAINT fk_ao_ci_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Zateceno stanje CI-jeva u AssetOPS-u';
