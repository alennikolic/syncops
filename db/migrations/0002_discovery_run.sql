-- db/migrations/0002_discovery_run.sql
--
-- Evidencija discovery poslova. Svaki pokusaj prikupljanja je jedan red,
-- bez obzira da li je uspeo.
--
-- Ova tabela je ono sto daje znacenje kolonama last_seen u inventory sloju:
-- bez podatka o tome da li je run bio kompletan, "poslednji put vidjeno"
-- ne znaci nista. Zato se status vodi i po tipu objekta posebno.
--
-- Zakljucavanje: generisana kolona active_lock drzi integration_id samo dok
-- je run u stanju queued ili running, i nad njom stoji UNIQUE indeks. Time
-- baza sama sprecava dva istovremena run-a nad istom integracijom — NULL
-- vrednosti se u MySQL-u ne sudaraju, pa zavrseni run-ovi ne smetaju.

CREATE TABLE discovery_run (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id  SMALLINT UNSIGNED NOT NULL,

    status          ENUM('queued','running','success','partial','failed')
                    NOT NULL DEFAULT 'queued',
    trigger_source  ENUM('gui','cli','schedule') NOT NULL DEFAULT 'cli',

    queued_at       DATETIME          NOT NULL COMMENT 'UTC',
    started_at      DATETIME          DEFAULT NULL COMMENT 'UTC',
    finished_at     DATETIME          DEFAULT NULL COMMENT 'UTC',
    heartbeat_at    DATETIME          DEFAULT NULL
                    COMMENT 'UTC; osvezava worker, sluzi za detekciju mrtvog posla',

    api_flavor      ENUM('rest_legacy','api_v2') DEFAULT NULL
                    COMMENT 'Dijalekt stvarno koriscen u ovom run-u',

    -- Status po tipu objekta. Ovo je ono sto "partial" cini upotrebljivim:
    -- ako su VM-ovi prosli a hostovi pukli, kasnija obrada to vidi.
    clusters_status ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    hosts_status    ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    vms_status      ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',

    clusters_found  INT UNSIGNED      NOT NULL DEFAULT 0,
    hosts_found     INT UNSIGNED      NOT NULL DEFAULT 0,
    vms_found       INT UNSIGNED      NOT NULL DEFAULT 0,

    clusters_changed INT UNSIGNED     NOT NULL DEFAULT 0,
    hosts_changed    INT UNSIGNED     NOT NULL DEFAULT 0,
    vms_changed      INT UNSIGNED     NOT NULL DEFAULT 0,

    api_calls       INT UNSIGNED      NOT NULL DEFAULT 0
                    COMMENT 'Broj HTTP poziva, radi procene opterecenja vCentera',

    error_message   TEXT              DEFAULT NULL,

    -- Kljuc za zakljucavanje: popunjen samo dok je run aktivan.
    active_lock     SMALLINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status IN ('queued','running')
                             THEN integration_id
                             ELSE NULL
                        END
                    ) VIRTUAL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_run_active (active_lock),
    KEY idx_run_integration (integration_id, queued_at),
    KEY idx_run_queue (status, queued_at),

    CONSTRAINT fk_run_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Discovery poslovi: red cekanja, status i brojaci';
