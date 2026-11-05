-- db/migrations/0009_api_flavor_varchar.sql
--
-- api_flavor prestaje da bude ENUM i postaje VARCHAR.
--
-- Zasto: vokabular dijalekata zavisi od TIPA integracije. "rest_legacy",
-- "api_v2" i "soap" su vCenter-ove reci; AssetOPS ce imati svoje, Kaspersky
-- svoje. ENUM sa svim vrednostima zajedno ne bi sprecio da se vCenter sacuva
-- sa dijalektom koji pripada drugom izvoru — proverava samo da je vrednost
-- IZ SPISKA, ne da je PRIKLADNA za taj tip.
--
-- Kolona "type" je od pocetka VARCHAR, iz istog razloga: da nov tip
-- integracije ne trazi migraciju. Ovim api_flavor sledi isto pravilo.
--
-- Druga, prakticna strana: svaka nova vrednost je do sada bila ALTER TABLE
-- na dva mesta. Dodavanje SOAP-a je tako proslo kroz migracije 0007 i 0008,
-- a druga od njih je otkrivena tek kao greska u radu ("Data truncated for
-- column 'api_flavor'"). Sa VARCHAR-om nov dijalekt je izmena u kodu.
--
-- Provera se ne gubi nego seli u IntegrationType, gde moze da bude i
-- preciznija: zna koji dijalekti vaze ZA KOJI tip.
--
-- Postojece vrednosti se prenose kakve jesu — ENUM se u VARCHAR pretvara bez
-- gubitka, jer su vrednosti i do sada bile tekst.

ALTER TABLE integration
    MODIFY api_flavor VARCHAR(32) NOT NULL DEFAULT 'auto'
           COMMENT 'Dijalekt; dozvoljene vrednosti zavise od type, vidi IntegrationType',
    MODIFY api_flavor_detected VARCHAR(32) DEFAULT NULL
           COMMENT 'Prepoznat dijalekt, kada je api_flavor = auto';

ALTER TABLE discovery_run
    MODIFY api_flavor VARCHAR(32) DEFAULT NULL
           COMMENT 'Dijalekt stvarno koriscen u ovom run-u';
