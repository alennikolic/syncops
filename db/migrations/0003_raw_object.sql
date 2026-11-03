-- db/migrations/0003_raw_object.sql
--
-- Sirov JSON svakog prikupljenog objekta, jedan red po objektu po run-u.
--
-- Svrha: kada se za pola godine ispostavi da treba polje koje maper danas
-- ne cita, ono se izvlaci odavde — bez ponovnog skeniranja infrastrukture.
--
-- payload je namerno MEDIUMTEXT a ne JSON tip: cilj je da se sacuva tacno
-- ono sto je API vratio, ukljucujuci i odgovor koji MySQL-ov JSON validator
-- ne bi prihvatio. Validacija je posao mapera, ne skladista.
--
-- Rast: 2000 VM-ova uz dnevni run je oko 730.000 redova godisnje. Politika
-- retencije (zadrzati poslednjih 30 dana + prvi run u mesecu) brise redove
-- po run_id-u i uvodi se kada bude bilo sta za brisanje.

CREATE TABLE raw_object (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,

    run_id         INT UNSIGNED      NOT NULL,
    integration_id SMALLINT UNSIGNED NOT NULL
                   COMMENT 'Denormalizovano iz discovery_run, radi brzeg filtriranja',

    object_type    ENUM('cluster','host','vm') NOT NULL,
    moref          VARCHAR(64)       NOT NULL
                   COMMENT 'Identifikator u vCenteru, npr. vm-1234',

    payload        MEDIUMTEXT        NOT NULL COMMENT 'Sirov odgovor API-ja',
    payload_hash   CHAR(64)          NOT NULL COMMENT 'SHA-256 normalizovanog payload-a',

    collected_at   DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    -- Jedan objekat se u okviru jednog run-a belezi tacno jednom.
    -- Istovremeno stiti od duplikata kada se VM lista particionise po
    -- klasterima pa se isti VM pojavi u dve particije.
    UNIQUE KEY uq_raw_run_object (run_id, object_type, moref),

    -- Istorija jednog objekta kroz run-ove.
    KEY idx_raw_history (integration_id, object_type, moref, collected_at),

    CONSTRAINT fk_raw_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_raw_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Sirov JSON po objektu po run-u';
