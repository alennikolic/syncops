# SyncOPS

Sistem za prikupljanje podataka o infrastrukturi iz više izvora i njihovu pripremu za sinhronizaciju sa CMDB bazom.

Prva faza pokriva **vCenter** integraciju: prikupljanje podataka o klasterima, ESXi hostovima i virtuelnim mašinama preko vSphere REST API-ja, uz čuvanje istorije detekcije i promena.

> **Status:** u razvoju. Okruženje, baza i web GUI rade; prikupljanje podataka sa vCentera se piše.

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

U `.env` promeniti `DB_PASSWORD` i `DB_ROOT_PASSWORD`.

### 2. Enkripcioni ključ i lozinka za GUI

Oba se generišu pre prvog podizanja, pa restart kasnije nije potreban.

```bash
docker compose run --rm app php bin/syncops key:generate
docker compose run --rm app php bin/syncops password:hash
```

Prva komanda ispisuje vrednost za `SECRET_KEY`, druga za `APP_PASSWORD_HASH`. Obe upisati u `.env`.

> **Navodnici su obavezni kod hash-a.** Bcrypt hash sadrži znak `$`, koji bi Compose protumačio kao referencu na promenljivu i pojeo deo stringa. Zato red mora izgledati ovako:
> ```
> APP_PASSWORD_HASH='$2y$10$...'
> ```

> **Ako se `SECRET_KEY` izgubi ili promeni**, svi sačuvani vCenter kredencijali postaju nedešifrabilni i moraju se uneti ponovo. To je svrha ključa, ne propust.

### 3. Podizanje kontejnera

```bash
docker compose up -d --build
```

Prvi build traje nekoliko minuta jer se kompajliraju PHP ekstenzije. Svako sledeće podizanje je trenutno.

```bash
docker compose ps
```

Sva tri servisa (`syncops-web`, `syncops-app`, `syncops-db`) treba da budu `running`, a `syncops-db` dodatno `healthy`.

### 4. Migracije baze

**Ovo je obavezan korak posle svakog podizanja kontejnera i posle svakog `git pull`-a.** Bez njega baza je prazna i GUI vraća grešku.

```bash
docker compose exec app php bin/syncops migrate
```

Očekivani ispis pri prvom pokretanju:

```
Primenjujem 6 migracija...

  0001  integration                     OK (17 ms)
  0002  discovery_run                   OK (21 ms)
  0003  raw_object                      OK (17 ms)
  0004  vc_cluster                      OK (551 ms)
  0005  vc_host                         OK (106 ms)
  0006  vc_vm                           OK (66 ms)

Gotovo. Ukupno primenjeno: 6.
```

Komanda je idempotentna — drugo pokretanje javlja `Nema novih migracija` i ne dira bazu. Bezbedno je pokrenuti je uvek.

### 5. GUI

Otvoriti **http://localhost:8080** i prijaviti se lozinkom iz koraka 2.

Ako se umesto forme za prijavu pojavi uputstvo, `APP_PASSWORD_HASH` nije stigao do kontejnera — proveri navodnike u `.env`, pa `docker compose up -d` i `docker compose restart app`.

---

## Rad sa CLI-jem

> **Zašto `php bin/syncops` a ne `bin/syncops`:** repozitorijum je bind-mountovan, pa kontejner vidi dozvole sa host mašine. Ako `bin/syncops` nema izvršnu dozvolu, direktno pozivanje daje `permission denied`. Pozivanje kroz `php` radi uvek, pa je u ovoj dokumentaciji svuda tako.
>
> Ko želi kraću varijantu: `chmod +x bin/syncops && git update-index --chmod=+x bin/syncops`.

```bash
# Migracije
docker compose exec app php bin/syncops migrate
docker compose exec app php bin/syncops migrate:status

# Integracije
docker compose exec app php bin/syncops integrations
docker compose exec app php bin/syncops integration:add
docker compose exec app php bin/syncops integration:password 1
docker compose exec app php bin/syncops integration:enable 1
docker compose exec app php bin/syncops integration:disable 1

# Generatori (ne zahtevaju bazu)
docker compose exec app php bin/syncops key:generate
docker compose exec app php bin/syncops password:hash

docker compose exec app php bin/syncops help
```

Isti poslovi se rade i kroz GUI; CLI postoji zato što discovery mora da ide kroz njega (vidi niže) i zato što je zgodan za skriptovanje.

### Korisne Docker komande

```bash
docker compose logs -f app          # PHP greške i php-fpm log
docker compose logs -f web          # nginx access i error log
docker compose exec app sh          # shell u aplikativnom kontejneru
docker compose exec db mysql -u root -p syncops   # SQL konzola

docker compose restart app          # posle izmene php.ini
docker compose up -d --build app    # posle izmene Dockerfile-a
docker compose down                 # zaustavljanje (podaci ostaju)
docker compose down -v              # zaustavljanje + BRISANJE baze
```

Izmene u PHP kodu se vide odmah — repozitorijum je bind-mountovan i OPcache je u razvojnom režimu.

---

## Konfiguracija

Sve ide preko `.env` fajla, koji **nije** u repozitorijumu.

| Promenljiva | Opis |
|---|---|
| `APP_ENV` | `dev` ili `prod` |
| `APP_PASSWORD_HASH` | bcrypt hash lozinke za GUI; prazno = GUI zatvoren |
| `WEB_PORT` | port GUI-ja na host mašini (podrazumevano `8080`) |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | pristupni podaci za bazu |
| `DB_ROOT_PASSWORD` | root lozinka MySQL-a |
| `DB_PORT_HOST` | port na koji se MySQL mapira na host (podrazumevano `3307`) |
| `SECRET_KEY` | 32 bajta u base64, za enkripciju vCenter kredencijala |

Kredencijali vCenter servera se **ne drže u `.env`** — unose se kroz GUI ili CLI i čuvaju enkriptovano u bazi.

### Prelazak na produkciju

Četiri mesta, sva označena komentarom u samim fajlovima:

- `docker/php/php.ini` — `display_errors = Off`, `opcache.validate_timestamps = 0`
- `docker/php/php.ini` i `src/Web/Auth.php` — uključiti `secure` kolačić kad aplikacija bude išla preko HTTPS-a
- `docker-compose.yml` — obrisati `ports` blok kod `db` servisa, da baza ne bude izložena
- `.env` — `APP_ENV=prod`

---

## Struktura repozitorijuma

```
syncops/
├── docker-compose.yml              # servisi: web, app, db (+ worker, zakomentarisan)
├── .env.example
├── .gitignore
├── composer.json                   # PSR-4 autoload, PHP zahtevi
├── README.md
│
├── docker/
│   ├── php/
│   │   ├── Dockerfile              # php:8.3-fpm-alpine + ekstenzije
│   │   └── php.ini                 # podešavanja za razvoj
│   └── nginx/
│       └── default.conf            # document root, front controller, PHP handler
│
├── bin/
│   └── syncops                     # jedini CLI entrypoint
│
├── config/
│   └── config.php                  # jedino mesto gde se čitaju env promenljive
│
├── db/
│   └── migrations/
│       ├── 0001_integration.sql    # definicije izvora + enkriptovani kredencijali
│       ├── 0002_discovery_run.sql  # red čekanja, status, brojači, zaključavanje
│       ├── 0003_raw_object.sql     # sirov JSON po objektu po run-u
│       ├── 0004_vc_cluster.sql     # inventory + journal promena
│       ├── 0005_vc_host.sql
│       └── 0006_vc_vm.sql
│
├── public/                         # JEDINI document root
│   ├── index.php                   # front controller
│   └── assets/
│       └── app.css
│
└── src/
    ├── Core/
    │   ├── Db.php                  # PDO konekcija, retry, UTC
    │   ├── Migrator.php            # primena migracija, checksum, drift
    │   └── Secret.php              # libsodium enkripcija kredencijala
    │
    ├── Integration/
    │   ├── Integration.php         # jedna definicija izvora (bez lozinke)
    │   └── IntegrationRepository.php
    │
    └── Web/
        ├── Router.php
        ├── Auth.php                # prijava jednom lozinkom, CSRF
        ├── View.php
        ├── Flash.php
        ├── Controller/
        │   ├── AuthController.php
        │   ├── DashboardController.php
        │   └── IntegrationController.php
        └── View/
            ├── layout.php
            ├── login.php
            ├── dashboard.php
            └── integration/
                ├── index.php
                └── form.php
```

### Još ne postoji

Sledeća faza donosi `src/Source/` sa `SourceInterface`, DTO-ima i `VCenter/` poddrvetom (`Client/`, `Collector/`, `Mapper/`), `src/Run/` sa orkestracijom discovery poslova, `src/Storage/` sa repozitorijumima inventory sloja i `tests/` sa snimljenim vCenter odgovorima.

### Principi iza strukture

**`public/` je jedini document root.** `src/`, `config/` i `.env` nisu ni mountovani u web kontejner, pa nisu dostupni preko HTTP-a ni greškom u konfiguraciji.

**PHP se izvršava samo kroz `index.php`.** Nginx tu lokaciju ima označenu kao `internal` — prolaze samo interna preusmerenja iz `try_files`. Svaki drugi `.php` fajl pod `public/` vraća 404.

**Jedan CLI entrypoint.** Sve komande idu kroz `bin/syncops`, sa jednim mestom za bootstrap. CLI radi i bez `composer install` — ako `vendor/` ne postoji, registruje se minimalni PSR-4 autoloader.

**Lozinke ne putuju uz objekte.** `Integration` namerno ne sadrži lozinku; za nju postoji zaseban poziv `IntegrationRepository::password()`, koji je dešifruje tek u trenutku prijave na API. Tako ne može da završi u `var_dump`-u, ispisu izuzetka ili logu.

**`Client/` će biti odvojen od `Collector/`.** Klijent zna samo kako da pozove API; kolektor zna kako da obiđe inventar. Zahvaljujući tome se novi protokol ili nova verzija API-ja dodaje kao nova klasa, bez izmena u kolektorima i maperima.

---

## Migracije baze

Šema se razvija kroz numerisane SQL fajlove u `db/migrations/`. Migrator vodi evidenciju u tabeli `schema_migrations` i primenjuje samo ono što nedostaje.

Pravila:

- **Primenjena migracija se ne menja.** Ispravka ide kao nova verzija. Migrator čuva SHA-256 svakog fajla i odbija da radi ako se sadržaj primenjene migracije promenio — izlazi sa kodom `2` i objašnjava šta se desilo.
- **Jedna logička izmena po fajlu.** MySQL ne povlači DDL unazad; ako fajl sa više naredbi pukne na trećoj, prve dve ostaju primenjene.
- **Nema `down` migracija.** Rollback na produkciji je vraćanje backup-a, ne pokretanje skripte koja nikad nije testirana.

```bash
docker compose exec app php bin/syncops migrate:status
```

---

## Model podataka

Podaci se čuvaju u dva sloja.

**Raw sloj** (`raw_object`) — sirov JSON svakog objekta, jedan red po objektu po run-u. Omogućava da se kasnije izvuče polje koje maper danas ne čita, bez ponovnog skeniranja infrastrukture.

**Inventory sloj** (`vc_cluster`, `vc_host`, `vc_vm`) — normalizovane tabele sa trenutnim stanjem, svaka sa pratećom `*_change` tabelom.

Svaki red u inventory sloju nosi:

| Kolona | Značenje |
|---|---|
| `first_seen` | prva detekcija objekta |
| `last_seen` | poslednja detekcija |
| `last_run_id` | run u kojem je poslednji put viđen |
| `payload_hash` | SHA-256 normalizovanog JSON-a |

Pri svakom run-u: ako je `payload_hash` nepromenjen, osvežavaju se samo `last_seen` i `last_run_id`. Ako se promenio, red se ažurira i upisuje se zapis u odgovarajuću `*_change` tabelu (`old_values`, `new_values`).

Tabela `discovery_run` beleži svaki run sa statusom (`queued`, `running`, `success`, `partial`, `failed`) i brojačima — i to **posebno po tipu objekta**, pa `partial` ima upotrebljivo značenje: vidi se da su VM-ovi prošli a hostovi pali. Bez podatka o tome da li je run bio kompletan, `last_seen` ne znači ništa, pa kasnija obrada uvek čita i status run-a.

Dva istovremena run-a nad istom integracijom sprečava sama baza: generisana kolona `active_lock` drži `integration_id` samo dok je run u stanju `queued` ili `running`, a nad njom stoji `UNIQUE` indeks.

### Identifikatori

Za svaki VM se povlače i čuvaju:

- `instance_uuid` — jedinstven u okviru vCentera, stabilan; primarni identifikator
- `bios_uuid` — SMBIOS UUID koji vidi gostujući OS; služi za korelaciju sa agentima na mašinama. **Nije jedinstven** — klonovi i restore ga umeju ponoviti
- `moref` — identifikator u okviru vCentera (`vm-1234`); nije stabilan, koristi se samo unutar run-a i za dijagnostiku

Ako VM pređe u drugi vCenter, nastaje nov red. Prepoznavanje da je reč o istoj mašini je zadatak kasnije faze transformacija.

---

## Poznata ograničenja vSphere REST API-ja

Ova ograničenja su ugrađena u dizajn i nisu greška u implementaciji:

**Limit po pozivu.** `GET /api/vcenter/vm` vraća najviše 4000 VM-ova (1000 u starijim verzijama), a preko toga vraća grešku umesto rezultata. Paginacije nema. Zato kolektor particioniše upit po datacentru i klasteru i deduplikuje rezultat po `moref`-u.

**Host podaci.** REST za hostove daje samo ime, `connection_state` i `power_state`. Hardverski podaci — proizvođač, model, serijski broj, CPU, RAM, ESXi build — **nisu dostupni preko REST-a**. Kolone za njih postoje u `vc_host` kao `NULL`, a kolona `hardware_source` razlikuje „nije prikupljeno" od „prikupljeno pa prazno". Popunjavaju se kada se doda SOAP klijent.

**Identitet VM-a.** Blok `identity` (`instance_uuid`, `bios_uuid`) postoji od vSphere API 6.7.1 i dostupan je samo iz detalja po VM-u, ne iz liste. Discovery zato pravi jedan dodatni poziv po mašini.

**Podaci iz gosta.** Hostname i IP adresa dolaze iz `guest/identity` — još jedan poziv po mašini, i to samo dok VMware Tools rade. Kolona `guest_state` beleži razlog kada podaci izostanu.

**Dva API dijalekta.** vSphere 6.x–7.0 koristi `/rest/` putanje i login preko `/rest/com/vmware/cis/session`, dok 7.0U2+ koristi `/api/` i `/api/session`. Dijalekt se bira po polju `api_flavor` u integraciji, uz automatsku detekciju pri prvom povezivanju.

---

## Zahtevi na strani vCentera

- servisni nalog sa rolom **Read-only** na root objektu, sa propagacijom
- nikakve druge privilegije nisu potrebne
- provera TLS sertifikata je podesiva po integraciji, jer se u praksi koriste self-signed sertifikati
- SyncOPS zatvara sesiju nakon svakog run-a, da ne bi iscrpeo limit konkurentnih sesija vCentera

---

## Roadmap

- [x] Razvojno okruženje: Docker Compose (nginx, PHP-FPM, MySQL)
- [x] Migracije baze i šema: integracije, run-ovi, raw sloj, inventory sa journalom
- [x] Osnovni sloj: PDO, enkripcija kredencijala, CLI entrypoint
- [x] Web GUI: prijava, pregled, upravljanje integracijama
- [ ] vCenter REST klijent, oba dijalekta, sa detekcijom verzije i proverom konekcije
- [ ] Cluster / Host / VM discovery, raw + inventory sloj, journal promena
- [ ] CLI worker i red čekanja; pokretanje discovery-ja iz GUI-ja
- [ ] Pregled prikupljenih podataka u GUI-ju, sa pretragom
- [ ] SOAP klijent za hardverske podatke o hostovima (serijski broj, model, CPU, RAM)
- [ ] Export prikupljenih podataka (XLSX / CSV)
- [ ] Sloj transformacija: korelacija objekata između vCentera
- [ ] Relacije između objekata (VM → Host → Cluster, VM → Datastore)
- [ ] Sinhronizacija prema CMDB bazi
- [ ] Integracija sa Kaspersky Security Center (radne stanice)

---

## Autor

Alen Nikolić
