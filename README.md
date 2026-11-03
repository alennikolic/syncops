# SyncOPS

Sistem za prikupljanje podataka o infrastrukturi iz više izvora i njihovu pripremu za sinhronizaciju sa CMDB bazom (AssetOPS).

Prva faza pokriva **vCenter** integraciju: prikupljanje podataka o klasterima, ESXi hostovima i virtuelnim mašinama preko vSphere REST API-ja, uz čuvanje istorije detekcije i promena.

> **Status:** u razvoju (v0.1 — vCenter discovery)

---

## Šta SyncOPS radi

SyncOPS je **među-korak** između izvornih sistema i CMDB-a. On ne donosi odluke o tome šta je CI, ne pravi relacije i ne briše podatke — on prikuplja, normalizuje i vodi evidenciju o tome kada je šta viđeno.

- povezuje se na **više vCenter servera** nezavisno
- prikuplja **Cluster / Host / VM** objekte
- čuva **sirovi JSON** svakog objekta (raw sloj) i **normalizovane tabele** (inventory sloj)
- vodi **journal** — `first_seen`, `last_seen` i zapis svake stvarne promene
- **nikada ne briše redove** — nestanak objekta se izvodi iz `last_seen`, a odluku o brisanju donosi kasnija obrada

Transformacije, korelacija objekata između vCentera i sinhronizacija prema AssetOPS-u su **sledeća faza** i nisu deo ovog repozitorijuma u trenutnoj verziji.

---

## Tehnološki stack

| Komponenta | Izbor |
|---|---|
| Aplikacija | PHP 8.3 (bez framework-a) |
| Baza | MySQL 8.x |
| Web server | nginx + PHP-FPM |
| Pokretanje | Docker + Docker Compose |
| vCenter API | vSphere Automation API (REST) |
| Testiranje | PHPUnit, uz snimljene vCenter fixture-e |

Zavisnosti su namerno svedene na minimum: `ext-curl`, `ext-pdo_mysql`, `ext-sodium`.

---

## Struktura repozitorijuma

```
syncops/
├── .github/
│   └── workflows/ci.yml            # lint + unit testovi
├── docker/
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   └── nginx/
│       └── default.conf
├── docker-compose.yml
├── .env.example
├── composer.json
├── README.md
│
├── bin/
│   ├── syncops                     # jedini CLI entrypoint
│   └── migrate                     # pokretanje migracija
│
├── config/
│   └── config.php                  # konfiguracija iz env promenljivih
│
├── db/
│   └── migrations/                 # numerisani SQL fajlovi
│       ├── 0001_integration.sql
│       ├── 0002_discovery_run.sql
│       ├── 0003_raw_object.sql
│       └── 0004_vcenter_inventory.sql
│
├── public/                         # JEDINI document root
│   ├── index.php                   # front controller
│   └── assets/
│
├── src/
│   ├── Core/                       # infrastrukturni sloj
│   │   ├── Db.php                  # PDO wrapper
│   │   ├── Secret.php              # enkripcija kredencijala (libsodium)
│   │   ├── Log.php                 # JSON log na stdout
│   │   └── Clock.php
│   │
│   ├── Run/                        # orkestracija discovery poslova
│   │   ├── DiscoveryRunner.php     # lock, zapis run-a, status
│   │   ├── RunRepository.php
│   │   └── RunStatus.php
│   │
│   ├── Source/                     # izvori podataka
│   │   ├── SourceInterface.php     # ugovor za svaki izvor
│   │   ├── Dto/
│   │   │   ├── ClusterDto.php
│   │   │   ├── HostDto.php
│   │   │   └── VmDto.php
│   │   └── VCenter/
│   │       ├── VCenterSource.php
│   │       ├── Client/             # transport i API dijalekti
│   │       │   ├── ClientInterface.php
│   │       │   ├── RestLegacyClient.php    # /rest   (6.7 – 7.0)
│   │       │   ├── RestClient.php          # /api    (7.0U2+)
│   │       │   ├── FlavorDetector.php
│   │       │   └── HttpTransport.php       # curl, curl_multi, retry
│   │       ├── Collector/          # KAKO se dohvata
│   │       │   ├── ClusterCollector.php
│   │       │   ├── HostCollector.php
│   │       │   └── VmCollector.php
│   │       └── Mapper/             # KAKO se prevodi u DTO
│   │           ├── HostMapper.php
│   │           └── VmMapper.php
│   │
│   ├── Storage/                    # perzistencija
│   │   ├── RawStore.php            # sirovi JSON po objektu po run-u
│   │   ├── ChangeJournal.php       # payload_hash + zapis promena
│   │   └── Repository/
│   │       ├── ClusterRepository.php
│   │       ├── HostRepository.php
│   │       └── VmRepository.php
│   │
│   ├── Integration/                # definicije integracija
│   │   ├── Integration.php
│   │   └── IntegrationRepository.php
│   │
│   └── Web/                        # GUI
│       ├── Router.php
│       ├── Controller/
│       │   ├── IntegrationController.php
│       │   ├── RunController.php
│       │   └── InventoryController.php
│       └── View/
│           ├── layout.php
│           └── integration/
│
├── tests/
│   ├── Unit/
│   └── Fixtures/
│       └── vcenter/                # snimljeni pravi API odgovori
│           ├── api/vm-list.json
│           ├── api/vm-detail.json
│           └── rest/host-list.json
│
└── var/                            # runtime, van git-a
    └── log/
```

### Principi iza strukture

**`public/` je jedini document root.** `src/`, `config/`, `.env` i `vendor/` su izvan njega i nisu dostupni preko web servera.

**Jedan CLI entrypoint.** Sve komande idu kroz `bin/syncops`, sa jednim mestom za bootstrap (autoload, konfiguracija, logger).

**`Client/` je odvojen od `Collector/`.** Klijent zna samo kako da pozove API; kolektor zna kako da obiđe inventar. Zahvaljujući tome se novi protokol ili nova verzija API-ja dodaje kao **nova klasa**, bez izmena u kolektorima i maperima.

**`Mapper/` je odvojen od `Collector/`.** Razlike između API dijalekata (npr. `{"value": {...}}` naspram golog JSON-a) gase se u maperu — dalje kroz aplikaciju putuje isključivo DTO.

**`Source/` je pripremljen za više izvora.** `SourceInterface` i `Dto/` postoje da bi se kasniji izvori (Kaspersky Security Center, čitanje iz AssetOPS-a) dodavali pored `VCenter/`, a ne kao paralelni sistem.

---

## Pokretanje

```bash
git clone <repo-url> syncops
cd syncops
cp .env.example .env
# popuniti .env (vidi niže)
docker compose up -d
docker compose exec app bin/syncops migrate
```

GUI je zatim dostupan na `http://localhost:8080`.

### Konfiguracija

Sve se podešava preko `.env` fajla, koji **nije** u repozitorijumu. Obavezne promenljive:

| Promenljiva | Opis |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | pristup MySQL bazi |
| `SECRET_KEY` | ključ za enkripciju vCenter kredencijala (libsodium, base64) |
| `APP_ENV` | `dev` ili `prod` |
| `TZ` | uvek `UTC` |

Kredencijali vCenter servera se **ne drže u `.env`** — unose se kroz GUI i čuvaju enkriptovano u bazi, ključem iz `SECRET_KEY`.

### CLI komande

```bash
bin/syncops migrate                      # primeni nove migracije
bin/syncops integrations                 # izlistaj integracije
bin/syncops test --integration=3         # proveri konekciju i detektuj API dijalekt
bin/syncops discover --integration=3     # pokreni discovery za jednu integraciju
bin/syncops discover --all               # pokreni discovery za sve aktivne
bin/syncops worker                       # worker petlja (obrađuje red poslova)
```

Discovery se **nikada ne izvršava kroz HTTP zahtev.** GUI samo upisuje posao u red; izvršava ga CLI worker.

---

## Model podataka

Podaci se čuvaju u dva sloja.

**Raw sloj** (`raw_object`) — sirov JSON svakog objekta, po run-u. Omogućava da se kasnije izvuče polje koje u prvoj verziji nije mapirano, bez ponovnog skeniranja infrastrukture.

**Inventory sloj** (`vc_cluster`, `vc_host`, `vc_vm`, …) — normalizovane tabele sa trenutnim stanjem.

Svaki red u inventory sloju nosi:

| Kolona | Značenje |
|---|---|
| `first_seen` | prva detekcija objekta |
| `last_seen` | poslednja detekcija |
| `last_run_id` | run u kojem je poslednji put viđen |
| `payload_hash` | SHA-256 normalizovanog JSON-a |

Pri svakom run-u: ako je `payload_hash` nepromenjen, osvežavaju se samo `last_seen` i `last_run_id`. Ako se promenio, red se ažurira i upisuje se zapis u odgovarajuću `*_change` tabelu (`old_values`, `new_values`).

Tabela `discovery_run` beleži svaki run sa statusom (`running`, `success`, `partial`, `failed`) i brojačima po tipu objekta. **Bez podatka o tome da li je run bio kompletan, `last_seen` nema značenje** — zato kasnija obrada uvek čita run status.

### Identifikatori

Za svaki VM se povlače i čuvaju:

- `instance_uuid` — jedinstven u okviru vCentera, stabilan; primarni identifikator
- `bios_uuid` — SMBIOS UUID koji vidi gost; služi za kasniju korelaciju sa agentima (Kaspersky i sl.)
- `moref` — identifikator u okviru vCentera (`vm-1234`); nije stabilan, koristi se samo unutar run-a i za dijagnostiku

Ako VM pređe u drugi vCenter, SyncOPS ga beleži kao nov red. Prepoznavanje da je reč o istoj mašini je zadatak kasnije faze transformacija.

---

## Poznata ograničenja vSphere REST API-ja

Ova ograničenja su ugrađena u dizajn i nisu bug:

**Limit po pozivu.** `GET /api/vcenter/vm` vraća najviše 4000 VM-ova (1000 u starijim verzijama), a preko toga vraća grešku umesto rezultata. Paginacije nema. Zato `VmCollector` particioniše upit po datacentru i klasteru i deduplikuje rezultat.

**Host podaci.** REST za hostove daje samo ime, `connection_state` i `power_state`. Hardverski podaci — proizvođač, model, serijski broj, CPU, RAM, ESXi build — **nisu dostupni preko REST-a**. Kolone za njih postoje u šemi kao `NULL` i biće popunjene kada se doda SOAP klijent (vidi roadmap).

**Identitet VM-a.** Blok `identity` (`instance_uuid`, `bios_uuid`) postoji od vSphere API 6.7.1 i dostupan je samo iz detalja po VM-u, ne iz liste. Discovery zato pravi jedan dodatni poziv po mašini.

**Dva API dijalekta.** vSphere 6.x–7.0 koristi `/rest/` putanje i login preko `/rest/com/vmware/cis/session`, dok 7.0U2+ koristi `/api/` i `/api/session`. Dijalekt se bira po polju `api_flavor` u integraciji, uz automatsku detekciju pri prvom povezivanju.

---

## Zahtevi na strani vCentera

- servisni nalog sa rolom **Read-only** na root objektu, sa propagacijom
- nikakve druge privilegije nisu potrebne
- provera TLS sertifikata je podesiva po integraciji (`verify_peer`), jer se u praksi koriste self-signed sertifikati
- SyncOPS zatvara sesiju nakon svakog run-a, da ne bi iscrpeo limit konkurentnih sesija vCentera

---

## Roadmap

- [x] vCenter REST klijent (oba dijalekta) i detekcija verzije
- [x] Cluster / Host / VM discovery, raw + inventory sloj
- [x] Journal detekcije i promena
- [ ] SOAP klijent za hardverske podatke o hostovima (serijski broj, model, CPU, RAM)
- [ ] Export prikupljenih podataka (XLSX / CSV)
- [ ] Sloj transformacija: korelacija objekata između vCentera
- [ ] Relacije između objekata (VM → Host → Cluster, VM → Datastore)
- [ ] Sinhronizacija prema AssetOPS-u
- [ ] Integracija sa Kaspersky Security Center (radne stanice)

---

## Autor

Alen Nikolić — MDS Informatički inženjering
