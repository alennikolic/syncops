-- db/migrations/0011_vc_vm_notes.sql
--
-- Notes sa virtuelne masine (vim25: config.annotation).
--
-- Zasto se isplati: Notes je jedino slobodno polje koje ljudi u vCenteru
-- stvarno popunjavaju. Tu po pravilu pise cemu masina sluzi, ko je trazio i
-- do kada zivi — podaci kojih nigde drugde nema, a za CMDB vrede vise od
-- vecine tehnickih polja.
--
-- TEXT a ne VARCHAR: vCenter dozvoljava do 65535 znakova, a ljudi u Notes
-- umeju da nalepe ceo opis sa prelomima redova.
--
-- Kolona ulazi i u comparable() u VmDto, pa izmena Notes-a u vCenteru
-- proizvodi zapis u vc_vm_change — sto je i poenta: promena namene masine je
-- upravo ono sto treba da se vidi u journalu.
--
-- Nije indeksirana. Pretraga ide kroz LIKE '%pojam%', koji indeks ionako ne
-- koristi; za ozbiljnu pretragu po tekstu bi trebao FULLTEXT, a to ima smisla
-- tek ako se pokaze da je spor.

ALTER TABLE vc_vm
    ADD COLUMN notes TEXT DEFAULT NULL
        COMMENT 'Notes iz vCentera (config.annotation)'
        AFTER guest_os;
