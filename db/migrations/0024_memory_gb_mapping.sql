-- db/migrations/0024_memory_gb_mapping.sql
--
-- RAM u mapiranju prelazi na GB.
--
-- Do sada se masinama nudio memory_mib, a hostovima memory_bytes. Oba su
-- bila ono sto baza drzi, ne ono sto covek cita: 4096 i 17179869184 umesto
-- 16. U AssetOPS je odlazio isti takav broj.
--
-- Polje memory_gb nije kolona nego se racuna u upitu, pa ovde nema sta da
-- se dodaje u semu. Ostaje samo da se postojeca podesavanja prevedu — bez
-- ovoga bi pokazivala na polje kog vise nema u katalogu, i tiho prestala da
-- upisuju bilo sta.
--
-- Deli se sa 1024, ne sa 1000: memorija se dodeljuje u stepenima dvojke, pa
-- 4096 MiB jeste ono sto svi zovu 4 GB.
--
-- POSLEDICA KOJU TREBA OCEKIVATI: prvi sledeci prolaz ce za svaki vec
-- upisan CI prijaviti izmenu tog polja, jer se vrednost menja sa 4096 na 4.
-- To je jednokratno i vidi se u Dnevniku upisa, po polju.

UPDATE ao_field_map
   SET source_field = 'memory_gb'
 WHERE source_kind = 'vm'
   AND source_field = 'memory_mib';

UPDATE ao_field_map
   SET source_field = 'memory_gb'
 WHERE source_kind = 'host'
   AND source_field = 'memory_bytes';
