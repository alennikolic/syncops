# SyncOPS

Sistem za prikupljanje podataka o infrastrukturi iz više izvora i njihovu pripremu za sinhronizaciju sa CMDB bazom.

Prva faza pokriva **vCenter** integraciju: prikupljanje podataka o klasterima, ESXi hostovima i virtuelnim mašinama preko vSphere REST API-ja, uz čuvanje istorije detekcije i promena.

> **Status:** u razvoju — podignuto razvojno okruženje, aplikativni sloj se piše.

---

## Šta SyncOPS radi

SyncOPS je **među-korak** između izvornih sistema i CMDB baze. On ne donosi odluke o tome šta je CI, ne pravi relacije i ne briše podatke — prikuplja, normalizuje i vodi evidenciju o tome kada je šta viđeno.

- povezuje se na **više vCenter servera** nezavisno
- prikuplja **Cluster / Host / VM** objekte
- čuva **sirovi JSON** svakog objekta (raw sloj) i **normalizovane tabele** (inventory sloj)
- vodi **journal** — `first_seen`, `last_seen` i zapis svake stvarne promene
- **nikada ne briše redove** — nestanak objekta se izvodi iz `last_seen`, a odluku o brisanju donosi kasnija obrada

Transformacije, korelacija objekata između vCentera i sama sinhronizacija prema CMDB bazi su **sledeća faza** i nisu deo ovog repozitorijuma u trenutnoj verziji.

---

## Tehnološki stack

| Komponenta | Izbor |
|---|---|
| Aplikacija | PHP 8.3 (bez framework-a) |
| Baza | MySQL 8.4 |
| Web server | nginx 1.27 + PHP-FPM |
| Pokretanje | Docker + Docker Compose |
| vCenter API | vSphere Automation API (REST) |
| Testiranje | PHPUnit, uz snimljene vCenter fixture-e |

Zavisnosti su svedene na PHP ekstenzije: `pdo_mysql`, `curl`, `sodium`, `json`, `mbstring`.

---

## Pokretanje

### Preduslovi

- Docker Engine 24+ sa Compose V2 (`docker compose`, ne `docker-compose`)
- slobodni portovi `8080` (GUI) i `3307` (MySQL na host mašini) — oba se mogu promeniti u `.env`

### 1. Kloniranje i priprema konfiguracije

```bash
git clone https://github.com/alennikolic/syncops.git
cd syncops
cp .env.example .env
```

### 2. Generisanje enkripcionog ključa

vCenter kredencijali se čuvaju enkriptovano u bazi. Ključ se generiše jednom:

```bash
docker compose run --rm app php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Dobijeni string upisati u `.env` kao vrednost `SECRET_KEY`. U istom koraku promeniti i `DB_PASSWORD` i `DB_ROOT_PASSWORD`.

> **Pažnja:** ako se `SECRET_KEY` kasnije izgubi ili promeni, svi sačuvani vCenter kredencijali postaju nedešifrabilni i moraju se uneti ponovo.

### 3. Podizanje kontejnera

```bash
docker compose up -d --build
```

Prvi build traje nekoliko minuta jer se kompajliraju PHP ekstenzije. Svako sledeće podizanje je trenutno.

Provera statusa:

```bash
docker compose ps
```

Sva tri servisa (`syncops-web`, `syncops-app`, `syncops-db`) treba da budu `running`, a `syncops-db` dodatno `healthy`.

### 4. Provera

Otvoriti **http://localhost:8080**

Dobija se status stranica koja proverava verziju PHP-a, vremensku zonu, prisustvo svih potrebnih ekstenzija, konekciju ka bazi i ispravnost `SECRET_KEY`. Ako su sve stavke označene sa `OK`, okruženje je spremno.

### Korisne komande

```bash
docker compose logs -f app          # PHP greške i php-fpm log
docker compose logs -f web          # nginx access i error log
docker compose exec app sh          # shell u aplikativnom kontejneru
docker compose exec db mysql -u root -p syncops   # SQL konzola

docker compose restart app          # restart posle izmene php.ini
docker compose up -d --build app    # rebuild posle izmene Dockerfile-a
docker compose down                 # zaustavljanje (podaci u bazi ostaju)
docker compose down -v              # zaustavljanje + BRISANJE baze
```

Izmene u PHP kodu se vide odmah — repozitorijum je bind-mountovan u `app` kontejner i OPcache je u razvojnom režimu. Restart je potreban samo posle izmene `php.ini`, a rebuild posle izmene `Dockerfile`-a.

---

## Konfiguracija

Sve se podešava preko `.env` fajla, koji **nije** u repozitorijumu.

| Promenljiva | Opis |
|---|---|
| `APP_ENV` | `dev` ili `prod` |
| `WEB_PORT` | port GUI-ja na host mašini (podrazumevano `8080`) |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | pristupni podaci za bazu |
| `DB_ROOT_PASSWORD` | root lozinka MySQL-a |
| `DB_PORT_HOST` | port na koji se MySQL mapira na host (podrazumevano `3307`) |
| `SECRET_KEY` | 32 bajta u base64 zapisu, za enkripciju vCenter kredencijala |

Kredencijali vCenter servera se **ne drže u `.env`** — unose se kroz GUI i čuvaju enkriptovano u bazi.

### Prelazak na produkciju

Tri mesta zahtevaju izmenu, sva su označena komentarom u samim fajlovima:

- `docker/php/php.ini` — `display_errors = Off`, `opcache.validate_timestamps = 0`, `session.cookie_secure = 1`
- `docker-compose.yml` — obrisati `ports` blok kod `db` servisa, da baza ne bude izložena
- `.env` — `APP_ENV=prod`

---

## Struktura repozitorijuma

### Trenutno u repozitorijumu

```
syncops/
├── docker-compose.yml              # definicija servisa: web, app, db
├── .env.example                    # obrazac konfiguracije
├── .gitignore
├── composer.json                   # PSR-4 autoload, PHP zahtevi
├── README.md
├── docker/
│   ├── php/
│   │   ├── Dockerfile              # php:8.3-fpm-alpine + ekstenzije
│   │   └── php.ini                 # podešavanja za razvoj
│   └── nginx/
│       └── default.conf            # document root, front controller, PHP handler
└── public/
    └── index.php                   # privremena status stranica
```

### Planirana struktura

```
syncops/
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
│       └── View/
│
└── tests/
    ├── Unit/
    └── Fixtures/
        └── vcenter/                # snimljeni pravi API odgovori
```

### Principi iza strukture

**`public/` je jedini document root.** `src/`, `config/` i `.env` nisu ni mountovani u web kontejner, pa nisu dostupni preko HTTP-a ni greškom u konfiguraciji.

**PHP se izvršava samo kroz `index.php`.** Nginx tu lokaciju ima označenu kao `internal` — prolaze samo interna preusmerenja iz `try_files`. Svaki drugi `.php` fajl pod `public/` vraća 404.

**Jedan CLI entrypoint.** Sve komande idu kroz `bin/syncops`, sa jednim mestom za bootstrap (autoload, konfiguracija, logger).

**`Client/` je odvojen od `Collector/`.** Klijent zna samo kako da pozove API; kolektor zna kako da obiđe inventar. Zahvaljujući tome se novi protokol ili nova verzija API-ja dodaje kao **nova klasa**, bez izmena u kolektorima i maperima.

**`Mapper/` je odvojen od `Collector/`.** Razlike između API dijalekata (npr. `{"value": {...}}` naspram golog JSON-a) gase se u maperu — dalje kroz aplikaciju putuje isključivo DTO.

**`Source/` je pripremljen za više izvora.** `SourceInterface` i `Dto/` postoje da bi se kasniji izvori (npr. Kaspersky Security Center, čitanje iz CMDB baze) dodavali pored `VCenter/`, a ne kao paralelni sistem.

---

## Planirane CLI komande

```bash
bin/syncops migrate                      # primeni nove migracije
bin/syncops integrations                 # izlistaj integracije
bin/syncops test --integration=3         # proveri konekciju i detektuj API dijalekt
bin/syncops discover --integration=3     # pokreni discovery za jednu integraciju
bin/syncops discover --all               # pokreni discovery za sve aktivne
bin/syncops worker                       # worker petlja (obrađuje red poslova)
```

Discovery se **nikada ne izvršava kroz HTTP zahtev** — web zahtev ima ograničenje trajanja, a skeniranje većeg vCentera ga probija. GUI samo upisuje posao u red; izvršava ga CLI worker.

U `docker-compose.yml` postoji pripremljen `worker` servis, zakomentarisan dok `bin/syncops` ne bude napisan.

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

Tabela `discovery_run` beleži svaki run sa statusom (`running`, `success`, `partial`, `failed`) i brojačima po tipu objekta. **Bez podatka o tome da li je run bio kompletan, `last_seen` nema značenje** — zato kasnija obrada uvek čita i status run-a.

### Identifikatori

Za svaki VM se povlače i čuvaju:

- `instance_uuid` — jedinstven u okviru vCentera, stabilan; primarni identifikator
- `bios_uuid` — SMBIOS UUID koji vidi gostujući OS; služi za kasniju korelaciju sa agentima na mašinama
- `moref` — identifikator u okviru vCentera (`vm-1234`); nije stabilan, koristi se samo unutar run-a i za dijagnostiku

Ako VM pređe u drugi vCenter, SyncOPS ga beleži kao nov red. Prepoznavanje da je reč o istoj mašini je zadatak kasnije faze transformacija.

---

## Poznata ograničenja vSphere REST API-ja

Ova ograničenja su ugrađena u dizajn i nisu greška u implementaciji:

**Limit po pozivu.** `GET /api/vcenter/vm` vraća najviše 4000 VM-ova (1000 u starijim verzijama), a preko toga vraća grešku umesto rezultata. Paginacije nema. Zato `VmCollector` particioniše upit po datacentru i klasteru i deduplikuje rezultat po `moref`-u.

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

- [x] Razvojno okruženje: Docker Compose (nginx, PHP-FPM, MySQL)
- [ ] Migracije baze i osnovni sloj (`Db`, `Secret`, `Log`)
- [ ] vCenter REST klijent, oba dijalekta, sa detekcijom verzije
- [ ] Cluster / Host / VM discovery, raw + inventory sloj
- [ ] Journal detekcije i promena
- [ ] GUI: integracije, pokretanje discovery-ja, pregled prikupljenih podataka
- [ ] SOAP klijent za hardverske podatke o hostovima (serijski broj, model, CPU, RAM)
- [ ] Export prikupljenih podataka (XLSX / CSV)
- [ ] Sloj transformacija: korelacija objekata između vCentera
- [ ] Relacije između objekata (VM → Host → Cluster, VM → Datastore)
- [ ] Sinhronizacija prema CMDB bazi
- [ ] Integracija sa Kaspersky Security Center (radne stanice)

---

## Autor

Alen Nikolić
