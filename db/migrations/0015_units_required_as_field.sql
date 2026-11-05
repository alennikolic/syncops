-- db/migrations/0015_units_required_as_field.sql
--
-- units_required prestaje da bude posebno podesavanje i postaje obicno polje
-- u tabeli mapiranja.
--
-- Zasto je uopste bio izdvojen: swagger ga u semi CreateAsset navodi u listi
-- "required", pa je izgledalo da bez njega kreiranje opreme pada. Korisnik,
-- koji AssetOPS koristi, kaze da nije obavezan — to je broj rek jedinica koje
-- server zauzima, podatak kao i svaki drugi. Swagger je tu u nesaglasnosti sa
-- ponasanjem servera.
--
-- To nije jedino takvo mesto: isti dokument belezi da GET sema navodi vendor,
-- status, power_consumption i tags kao obavezne, a kreiranje prolazi i bez
-- njih. Semama ovog API-ja se ne veruje na rec; proverava se pozivom.
--
-- Posledica: u ao_mapping ostaje samo tip CI-ja, sto je i jedino sto zaista
-- nije vrednost polja nego odluka koje je VRSTE CI koji se pravi.

ALTER TABLE ao_mapping
    DROP COLUMN units_required;
