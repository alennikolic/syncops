-- db/migrations/0023_sync_action_changes.sql
--
-- Sta se tacno promenilo u jednom upisu.
--
-- Dnevnik je do sada cuvao poslato telo. Telo odgovara na pitanje „sta je
-- otislo", ali ne i na „sta se promenilo" — kod izmene se salje CEO
-- procitani CI, pa se iz njega ne vidi koja su polja zapravo dirnuta. Da
-- bi se to videlo, trebalo bi uporediti telo sa zatecenim stanjem koje
-- vise ne postoji.
--
-- Planner tu razliku vec racuna, po polju, sa zatecenom i novom vrednoscu.
-- Do sada je to zivelo samo na ekranu plana i nestajalo sa upisom. Sada se
-- upisuje uz red dnevnika.
--
-- Oblik: [{"ref":"name","from":"stara","to":"nova"}, …]
--
-- Kod kreiranja ostaje NULL: tamo je sve novo, pa je poslato telo vec pun
-- odgovor na pitanje sta je upisano.

ALTER TABLE sync_action
    ADD COLUMN changes JSON DEFAULT NULL
        COMMENT 'Izmene po polju: ref, from, to. NULL kod kreiranja.'
        AFTER payload;
