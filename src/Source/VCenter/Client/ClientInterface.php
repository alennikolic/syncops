<?php
// src/Source/VCenter/Client/ClientInterface.php
//
// Ugovor koji svaki vCenter klijent ispunjava, bez obzira na protokol.
//
// Zasto postoji: REST ne daje hardverske podatke o hostovima (proizvodjac,
// model, serijski broj, CPU, RAM). Kada se doda SOAP klijent, on ce ispuniti
// isti ovaj ugovor i getHostHardware() ce prestati da vraca null — kolektori
// i maperi se nece dirati.
//
// Sve metode vracaju vec raspakovane nizove: /rest dijalekt svoj odgovor
// umotava u {"value": ...}, i to se gasi ovde, u klijentu, da kolektori
// vide identican oblik bez obzira na verziju API-ja.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

interface ClientInterface
{
    /**
     * Prijavi se i zapamti sesijski token.
     *
     * @throws \SyncOps\Source\VCenter\VCenterException
     */
    public function login(): void;

    /**
     * Zatvori sesiju. vCenter ima limit konkurentnih sesija, pa se ovo zove
     * uvek, i kada je run pukao.
     */
    public function logout(): void;

    /** Oznaka dijalekta koji ovaj klijent govori. */
    public function flavor(): string;

    /**
     * Verzija vCentera, ako je nalog sme procitati. Read-only nalog nema uvek
     * pravo na /appliance putanje, pa null nije greska.
     */
    public function productVersion(): ?string;

    /**
     * @return list<array<string, mixed>> datacenter, name
     */
    public function listDatacenters(): array;

    /**
     * @param list<string> $datacenterIds Prazno = svi
     * @return list<array<string, mixed>> cluster, name, ha_enabled, drs_enabled
     */
    public function listClusters(array $datacenterIds = []): array;

    /**
     * @param list<string> $clusterIds Prazno = svi
     * @return list<array<string, mixed>> host, name, connection_state, power_state
     */
    public function listHosts(array $clusterIds = [], array $datacenterIds = []): array;

    /**
     * Hardverski podaci o hostu. REST ih nema, pa vraca null; SOAP klijent ce
     * ih popuniti.
     *
     * @return array<string, mixed>|null
     */
    public function getHostHardware(string $hostId): ?array;

    /**
     * @param list<string> $clusterIds Prazno = svi
     * @return list<array<string, mixed>> vm, name, power_state, cpu_count, memory_size_MiB
     */
    public function listVms(array $clusterIds = [], array $datacenterIds = []): array;

    /**
     * Masine na jednom hostu. Sluzi da se smestaj (VM -> host) sazna jednim
     * pozivom po hostu, umesto jednim po masini.
     *
     * @return list<array<string, mixed>>
     */
    public function listVmsOnHost(string $hostId): array;

    /**
     * Detalji jedne masine, ukljucujuci blok identity sa instance_uuid i
     * bios_uuid. Dostupno samo odavde, ne iz liste.
     *
     * @return array<string, mixed>
     */
    public function getVm(string $vmId): array;

    /**
     * Hostname i IP iz gosta. Zahteva da VMware Tools rade; vraca null kada
     * ne rade ili je masina ugasena.
     *
     * @return array<string, mixed>|null
     */
    public function getVmGuestIdentity(string $vmId): ?array;

    /**
     * Uredjaji svih hostova: mrezne kartice, VMkernel adapteri, standardni
     * svicevi, port grupe i HBA.
     *
     * Zaseban prolaz, ne dopuna listHosts(): ova polja uvecavaju odgovor za
     * hostove vise od dvesta puta, a hostovi se citaju vise puta.
     *
     * Kljucevi jednog reda: host_moref, kind (pnic, vmk, vswitch, portgroup,
     * hba), device, pa polja koja toj vrsti pripadaju. Polje koje vCenter
     * nije vratio IZOSTAJE iz niza.
     *
     * @return list<array<string, mixed>>
     */
    public function listHostDevices(): array;

    /**
     * Datastore-ovi, svi jednim pozivom.
     *
     * Kljucevi jednog reda: datastore (moref), name, type, capacity,
     * free_space, uncommitted, accessible, multiple_host_access,
     * maintenance_mode, overall_status, url, hosts (moref-ovi hostova koji ga
     * montiraju), vmfs_uuid, vmfs_version.
     *
     * Polje koje vCenter nije vratio IZOSTAJE iz niza, umesto da dodje kao
     * prazna vrednost — tako maper razlikuje "nema podatka" od nule.
     *
     * @return list<array<string, mixed>>
     */
    public function listDatastores(): array;

    /**
     * Licence sa vCentera, sve jednim pozivom.
     *
     * Ne vraca prostu listu nego i objasnjenje zasto je prazna. Citanje
     * licenci trazi pravo Global.Licenses; kada ga nalog nema, vCenter NE
     * vraca gresku nego uspesan odgovor bez vrednosti. Prazna lista bi zato
     * bila dvosmislena — ili vCenter nema licenci, ili ih nalog ne vidi.
     * missingPrivilege razdvaja to dvoje: null znaci da je citanje proslo.
     *
     * Kljucevi jednog reda: key, name, edition_key, total, used, cost_unit,
     * properties (skalarne osobine: ProductName, ProductVersion,
     * expirationDate...).
     *
     * @return array{licenses: list<array<string, mixed>>, missingPrivilege: ?string}
     */
    public function listLicenses(): array;

    /** Koliko je HTTP poziva ovaj klijent do sada napravio. */
    public function callCount(): int;
}
