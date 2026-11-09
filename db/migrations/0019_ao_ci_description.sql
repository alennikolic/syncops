-- db/migrations/0019_ao_ci_description.sql
--
-- Nativni opis CI-ja u svoju kolonu, kao sto su vec name i serial_number.
--
-- Zasto tek sada: migracija 0016 je u komentaru zapisala da description
-- postoji samo u detaljima. To vazi za entitet asset; kod virtual-asset ga
-- nosi i ruta liste. Komentar u 0016 se ne ispravlja — primenjena migracija
-- se ne dira, jer migrator cuva checksum fajla i izmena bi se prijavila kao
-- drift. Ispravka stoji ovde.
--
-- Opis je i do sada bio u bazi, unutar payload-a, i faza poredjenja ga je
-- odatle citala. Nedostajao je kao kolona, pa se nije video u prikazu niti
-- mogao da se filtrira bez raspakivanja JSON-a.
--
-- TEXT a ne VARCHAR: opis je slobodan tekst, bez gornje granice u AssetOPS-u.
-- Zato nije ni u indeksu.

ALTER TABLE ao_ci
    ADD COLUMN description TEXT DEFAULT NULL
        COMMENT 'Nativno polje description, kako ga vraca ruta liste'
        AFTER name;
