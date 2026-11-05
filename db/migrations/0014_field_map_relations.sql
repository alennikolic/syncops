-- db/migrations/0014_field_map_relations.sql
--
-- Status, lokacija i visibility grupe prelaze u tabelu mapiranja polja.
--
-- Do sada su stajali u ao_mapping, kao posebna podesavanja. Ispostavilo se da
-- su to obicna polja CI-ja — samo im vrednost ne dolazi iz vCentera nego iz
-- spiska prikupljenog iz AssetOPS-a. Dva ekrana za istu stvar su znacila i
-- dva mesta na kojima se gleda "cime se ovo puni".
--
-- Otud treca vrsta cilja. Razlika nije kozmeticka — pri upisu se tri vrste
-- salju drugacije:
--   native    obicna vrednost (name, serial_number)
--   eav       vrednost ciji oblik zavisi od tipa atributa
--   relation  SLUG, ili niz slugova kod visibility grupa
--
-- U ao_mapping ostaju samo tip CI-ja i units_required. Tip nije polje nego
-- odluka koje je VRSTE CI koji se pravi, pa se bira pre tabele, a ne u njoj.

ALTER TABLE ao_field_map
    MODIFY target_kind ENUM('native','eav','relation') NOT NULL
           COMMENT 'native = polje CI-ja, eav = atribut, relation = veza (slug)';

ALTER TABLE ao_mapping
    DROP COLUMN location_slug,
    DROP COLUMN visibility_groups;
