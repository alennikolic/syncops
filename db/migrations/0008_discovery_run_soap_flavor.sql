-- db/migrations/0008_discovery_run_soap_flavor.sql
--
-- Dopuna uz 0007: i discovery_run belezi dijalekt, u svojoj koloni istog
-- imena, pa je i njen ENUM morao da dobije "soap".
--
-- Zasto je promaklo: 0007 je menjala integration, gde se dijalekt PODESAVA.
-- discovery_run ga ZAPISUJE, posle svakog pokretanja, i to je zaseban ENUM.
-- Posledica je bila da podesavanje prodje, test veze prodje, a prikupljanje
-- padne na upisu — "Data truncated for column 'api_flavor'".
--
-- Ostali ENUM-ovi nisu dirani namerno: Vim25Client vrednosti stanja prevodi
-- u REST oblik (poweredOn -> POWERED_ON), pa vc_host.power_state,
-- vc_host.connection_state i vc_vm.power_state vec prihvataju ono sto SOAP
-- donosi.

ALTER TABLE discovery_run
    MODIFY api_flavor ENUM('rest_legacy','api_v2','soap') DEFAULT NULL
           COMMENT 'Dijalekt stvarno koriscen u ovom run-u';
