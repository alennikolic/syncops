-- db/migrations/0007_integration_soap_flavor.sql
--
-- Dodaje "soap" kao ravnopravan dijalekt integracije.
--
-- Do sada je SOAP bio samo dopuna REST-u, za hardver hostova koji REST ne
-- daje. Sada moze da bude i jedini protokol: vSphere Web Services API (vim25)
-- je pun API, a REST njegov podskup.
--
-- Zasto bi se neko odlucio za soap:
--   - REST uopste ne vraca VM templejte, pa oni u CMDB-u izgledaju kao da su
--     nestali cim ih neko konvertuje
--   - REST trazi poziv po masini za instance_uuid i jos jedan za podatke iz
--     gosta; SOAP ceo inventar vrati jednim pozivom
--   - REST /api postoji tek od 7.0U2 i ima ogranicenje od 4000 objekata
--
-- "auto" se i dalje odnosi samo na REST dijalekte: soap se bira svesno, jer
-- nije stvar verzije vCentera nego odluke kako se prikuplja.
--
-- api_flavor_detected takodje dobija "soap", jer se posle uspesnog testa u
-- nju upisuje ono sto je klijent prijavio.

ALTER TABLE integration
    MODIFY api_flavor ENUM('auto','rest_legacy','api_v2','soap')
           NOT NULL DEFAULT 'auto'
           COMMENT 'rest_legacy = /rest (6.7-7.0), api_v2 = /api (7.0U2+), soap = /sdk (vim25)',
    MODIFY api_flavor_detected ENUM('rest_legacy','api_v2','soap') DEFAULT NULL;
