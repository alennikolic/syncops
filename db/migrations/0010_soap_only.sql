-- db/migrations/0010_soap_only.sql
--
-- vCenter se od sada cita iskljucivo preko vim25 (/sdk). REST klijenti su
-- uklonjeni iz koda, pa integracija koja i dalje nosi REST dijalekt ne bi
-- imala cime da se poveze.
--
-- Zasto je REST otpao, mereno na istom inventaru od 156 masina:
--   REST  255 poziva, 10 s, bez templejta, bez hardvera hostova
--   SOAP   18 poziva, 0,7 s, 156/156 instance_uuid, 5/5 hostova sa hardverom
-- Uz to, REST /api postoji tek od 7.0U2 i ima ogranicenje od 4000 objekata,
-- dok vim25 radi od vSphere 5.5 naovamo i nema taj limit.
--
-- Prevode se SAMO vCenter integracije. Kada stignu drugi tipovi izvora, oni
-- ce imati svoje dijalekte i ovo ih se ne tice.

UPDATE integration
   SET api_flavor = 'soap',
       updated_at = UTC_TIMESTAMP()
 WHERE type = 'vcenter'
   AND api_flavor <> 'soap';

-- Prepoznat dijalekt je imao smisla samo dok je postojao "auto". Brise se da
-- u GUI-ju ne bi stajalo da je prepoznat /api na integraciji koja govori SOAP.
UPDATE integration
   SET api_flavor_detected = NULL
 WHERE type = 'vcenter'
   AND api_flavor_detected IS NOT NULL
   AND api_flavor_detected <> 'soap';

-- discovery_run se NE dira. Ti redovi beleze kojim je dijalektom run stvarno
-- radjen, i za stare run-ove je to i dalje /api. Prepravljanje bi bilo
-- falsifikovanje istorije prikupljanja.
