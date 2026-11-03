# SyncOPS

Sistem za prikupljanje podataka o infrastrukturi iz više izvora i njihovu pripremu za sinhronizaciju sa CMDB bazom.

Prva faza pokriva **vCenter** integraciju: prikupljanje podataka o klasterima, ESXi hostovima i virtuelnim mašinama preko vSphere REST API-ja, uz čuvanje istorije detekcije i promena.

> **Status:** radi. Prikupljanje, skladištenje, worker i web GUI su gotovi. Sledi SOAP klijent za hardver hostova i faza transformacija.

---

## Šta SyncOPS radi

SyncOPS je **među-korak** između izvornih sistema i CMDB baze. On ne donosi odluke o tome šta je CI, ne pravi relacije i ne briše podatke — prikuplja, normalizuje i vodi evidenciju o tome kada je šta viđeno.

- povezuje se na **više vCenter servera** nezavisno
- prikuplja **Cluster / Host / VM** objekte
- čuva **sirovi JSON** svakog objekta (raw sloj) i **normalizovane tabele** (inventory sloj)
- vodi **journal** — `first_seen`, `last_seen` i zapis svake stvarne promene, po poljima
- **nikada ne briše redove** — nestanak objekta se izvodi iz `last_seen`, a odluku o brisanju donosi kasnija obrada
- prikazuje prikupljeno u web GUI-ju, sa pretragom, filterima i izvozom u CSV

Transformacije, korelacija objekata između vCentera i sama sinhronizacija prema CMDB bazi su **sledeća faza** i nisu deo ovog repozitorijuma.

### Izmereno na stvarnom okruženju

| | |
|---|---|
| vCenter | 8.0.3, dijalekt `/api` |
| Obim | 1 datacentar, 2 klastera, 5 hostova, 153 mašine |
| Prikupljanje | **10 sekundi**, 255 HTTP poziva |
| Popunjenost | `instance_uuid` 153/153, smeštaj na host 153/153, IP adresa 70/153 |

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

Bez ijedne spoljne biblioteke. Zavisnosti su samo PHP ekstenzije: `pdo_mysql`, `curl`, `sodium`, `json`, `mbstring`, `pcntl`. Aplikacija radi i bez `composer install` — ako `vendor/` ne postoji, registruje se minimalni PSR-4 autoloader.

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

> **Navodnici su obavezni kod hash-a.** Bcrypt hash sadrži znak `$`, koji bi Compose protumačio kao referencu na promenljivu i pojeo deo stringa:
> ```
> APP_PASSWORD_HASH='$2y$10$...'
> ```

> **Ako se `SECRET_KEY` izgubi ili promeni**, svi sačuvani vCenter kredencijali postaju nedešifrabilni i moraju se uneti ponovo. To je svrha ključa, ne propust.

### 3. Podizanje kontejnera

```bash
docker compose up -d --build
docker compose ps
```

Podiže se četiri servisa: `syncops-web`, `syncops-app`, `syncops-db` i `syncops-worker`. Baza dodatno treba da bude `healthy`.

Prvi build traje nekoliko minuta jer se kompajliraju PHP ekstenzije.

### 4. Migracije baze

**Obavezan korak posle svakog podizanja kontejnera i posle svakog `git pull`-a.** Bez njega baza je prazna i GUI vraća grešku.

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

### 5. Prvi vCenter

```bash
docker compose exec app php bin/syncops integration:add
docker compose exec app php bin/syncops integration:test 1
docker compose exec app php bin/syncops discover 1
```

Isto se radi i kroz GUI, na **http://localhost:8080**, prijavom lozinkom iz koraka 2.

Ako se umesto forme za prijavu pojavi uputstvo, `APP_PASSWORD_HASH` nije stigao do kontejnera — proveri navodnike u `.env`, pa `docker compose up -d`.

---

## Web GUI

| Stranica | Sadržaj |
|---|---|
| **Pregled** | brojači i stanje svake integracije, sa ishodom poslednjeg prikupljanja |
| **Virtuelne mašine** | tabela sa pretragom, pet filtera, straničenjem i izvozom u CSV |
| **Detalji mašine** | svi atributi po kategorijama + journal promena |
| **Hostovi** | stanje, klaster, broj mašina, hardver |
| **Klasteri** | HA, DRS, broj hostova i mašina |
| **Prikupljanja** | istorija run-ova sa ishodom, brojačima i trajanjem |
| **Integracije** | dodavanje i izmena vCentera, provera veze, pokretanje prikupljanja |

### Oznaka „nije viđeno u poslednjem prikupljanju"

Svaka tabela poredi `last_run_id` objekta sa **poslednjim uspešnim** run-om te integracije. Ako zaostaje, red dobija crvenu oznaku.

Delimični i neuspeli run-ovi se namerno **ne računaju** kao referenca: posle njih odsustvo objekta može da znači samo to da prikupljanje nije stiglo do njega. SyncOPS ni tada ne zaključuje da je objekat obrisan — red ostaje u bazi, a odluku donosi kasnija obrada.

### Izvoz u CSV

Dugme **Izvezi CSV** izvozi tačno ono što je na ekranu — sa primenjenim uslovima, ali bez straničenja. Dvadeset šest kolona, uključujući oba UUID-a, oba opisa operativnog sistema i kolonu `nije_u_poslednjem_runu`.

Fajl je pripremljen za Excel: ima BOM (bez njega dijakritika stiže izlomljena), prvu liniju `sep=;` (bez nje bi na nekim lokalizacijama ceo red završio u jednoj koloni) i zaštitu od formula — ćelija koja počinje sa `=`, `+` ili `@` dobija apostrof, jer bi je Excel inače izvršio.

Mere su u osnovnim jedinicama i kao **celi brojevi**: `ram_mib`, `kapacitet_diskova_bajtova`. Vrednost `4.5 GiB` bi u Excelu sa zarezom kao decimalnim znakom stigla kao tekst, pa se po njoj ne bi moglo računati.

---

## Prikupljanje: odmah ili kroz red čekanja

Dva načina, oba rade isto:

**Odmah** — `discover <id>` prikuplja i upisuje sinhrono, ispisuje ishod kad završi. Za CLI, kada se čeka rezultat.

**Kroz red čekanja** — GUI dugme „Prikupi" i `discover:queue` samo upisuju posao sa statusom `queued`. Preuzima ga worker. Ovako ide zato što prikupljanje na većem vCenteru traje minutima i probilo bi ograničenje trajanja web zahteva.

### Worker

Radi kao zaseban kontejner i proverava red na svakih 5 sekundi.

```bash
docker compose logs -f worker
```

Dve stvari koje rešava:

**Gašenje ne prekida posao.** Na `SIGTERM` (`docker compose stop`/`restart`) worker završava tekuće prikupljanje pa tek onda izlazi. Zato je `stop_grace_period: 5m` — podrazumevanih 10 sekundi bi Docker iskoristio da ga ubije nasred posla, a to ostavlja run u stanju `running` i time **zaključava integraciju**.

**Zaglavljeni poslovi se oslobađaju.** Na svakom prolazu worker označava kao neuspele poslove bez znaka života duže od 30 minuta. Heartbeat se osvežava na svakih 25 mašina, pa dug run neće biti pogrešno proglašen mrtvim.

Ručno oslobađanje, ako zatreba: `runs:reset <broj posla>`.

### Zakazivanje

```
17 */6 * * *  cd /root/syncops && docker compose exec -T app php bin/syncops discover:queue --all
```

Minut 17 namerno, a ne 0 — da se ne poklopi sa svim ostalim poslovima na mašini.

---

## Rad sa CLI-jem

> **Zašto `php bin/syncops` a ne `bin/syncops`:** repozitorijum je bind-mountovan, pa kontejner vidi dozvole sa host mašine. Ako `bin/syncops` nema izvršnu dozvolu, direktno pozivanje daje `permission denied`. Pozivanje kroz `php` radi uvek.
>
> Kraća varijanta: `chmod +x bin/syncops && git update-index --chmod=+x bin/syncops`.

```bash
# Migracije
docker compose exec app php bin/syncops migrate
docker compose exec app php bin/syncops migrate:status

# Integracije
docker compose exec app php bin/syncops integrations
docker compose exec app php bin/syncops integration:add
docker compose exec app php bin/syncops integration:test 1
docker compose exec app php bin/syncops integration:password 1
docker compose exec app php bin/syncops integration:enable 1
docker compose exec app php bin/syncops integration:disable 1

# Prikupljanje
docker compose exec app php bin/syncops collect:dry 1        # prikupi i ispiši, BEZ upisa
docker compose exec app php bin/syncops discover 1           # prikupi i upiši, odmah
docker compose exec app php bin/syncops discover:queue 1     # u red čekanja
docker compose exec app php bin/syncops discover:queue --all # sve aktivne, za cron
docker compose exec app php bin/syncops runs:reset 7         # otključaj zaglavljen posao

# Generatori (ne zahtevaju bazu)
docker compose exec app php bin/syncops key:generate
docker compose exec app php bin/syncops password:hash

docker compose exec app php bin/syncops help
```

`collect:dry` je korisna pre nego što se išta upiše: ispisuje tabele prikupljenog i odeljak **kvalitet podataka** — koliko mašina ima `instance_uuid`, koliko IP adresu, koliko razrešen smeštaj.

### Korisne Docker komande

```bash
docker compose logs -f app          # PHP greške i php-fpm log
docker compose logs -f worker       # tok prikupljanja
docker compose logs -f web          # nginx
docker compose exec app sh          # shell u kontejneru
docker compose exec db mysql -u root -p syncops

docker compose restart app          # posle izmene php.ini ili PHP koda
docker compose up -d --build        # posle izmene Dockerfile-a
docker compose down                 # zaustavljanje (podaci ostaju)
docker compose down -v              # zaustavljanje + BRISANJE baze
```

Izmene u PHP kodu se vide odmah — repozitorijum je bind-mountovan i OPcache je u razvojnom režimu. Izmene u CSS-u takođe: putanja stila nosi verziju iz vremena izmene fajla, pa keš pregledača ne stoji na putu.

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

Kredencijali vCenter servera se **ne drže u `.env`** — unose se kroz GUI ili CLI i čuvaju enkriptovano u bazi (libsodium secretbox).

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
├── docker-compose.yml              # servisi: web, app, db, worker
├── .env.example
├── composer.json                   # PSR-4 autoload, PHP zahtevi
│
├── docker/
│   ├── php/{Dockerfile,php.ini}
│   └── nginx/default.conf
│
├── bin/syncops                     # jedini CLI entrypoint
├── config/config.php               # jedino mesto gde se čitaju env promenljive
│
├── db/migrations/
│   ├── 0001_integration.sql        # izvori + enkriptovani kredencijali
│   ├── 0002_discovery_run.sql      # red čekanja, status, brojači, zaključavanje
│   ├── 0003_raw_object.sql         # sirov JSON po objektu po run-u
│   ├── 0004_vc_cluster.sql         # inventory + journal promena
│   ├── 0005_vc_host.sql
│   └── 0006_vc_vm.sql
│
├── public/                         # JEDINI document root
│   ├── index.php                   # front controller
│   └── assets/app.css
│
└── src/
    ├── Core/
    │   ├── Db.php                  # PDO konekcija, retry, UTC
    │   ├── Migrator.php            # migracije, checksum, drift
    │   └── Secret.php              # libsodium enkripcija kredencijala
    │
    ├── Integration/                # definicije izvora
    │   ├── Integration.php         # bez lozinke, namerno
    │   └── IntegrationRepository.php
    │
    ├── Source/
    │   ├── Dto/                    # ClusterDto, HostDto, VmDto, InventoryDto
    │   └── VCenter/
    │       ├── VCenterSource.php   # orkestracija prikupljanja
    │       ├── ConnectionTester.php
    │       ├── Client/             # transport i dijalekti API-ja
    │       │   ├── HttpTransport.php
    │       │   ├── AbstractRestClient.php
    │       │   ├── RestClient.php          # /api   (7.0U2+)
    │       │   ├── RestLegacyClient.php    # /rest  (6.7–7.0)
    │       │   └── ClientFactory.php       # auto-detekcija dijalekta
    │       ├── Collector/          # KAKO se obilazi inventar
    │       └── Mapper/             # KAKO se odgovor prevodi u DTO
    │
    ├── Storage/
    │   ├── RawStore.php            # sirov JSON, grupni upis
    │   ├── InventoryRepository.php # upsert + journal, napisano jednom
    │   ├── InventoryQuery.php      # čitanje za prikaz i izvoz
    │   └── Repository/             # Cluster, Host, Vm
    │
    ├── Run/
    │   ├── DiscoveryRunner.php     # prikupljanje + upis, u transakciji
    │   ├── Worker.php              # petlja nad redom čekanja
    │   ├── RunRepository.php
    │   ├── RunStatus.php
    │   └── RunOutcome.php
    │
    └── Web/
        ├── Router.php, Auth.php, View.php, Flash.php, CsvExport.php
        ├── Controller/             # Auth, Dashboard, Integration, Inventory, Run
        └── View/                   # layout, login, dashboard, integration/, inventory/, run/
```

### Principi iza strukture

**`public/` je jedini document root.** `src/`, `config/` i `.env` nisu ni mountovani u web kontejner.

**PHP se izvršava samo kroz `index.php`.** Nginx tu lokaciju ima označenu kao `internal` — prolaze samo interna preusmerenja iz `try_files`. Svaki drugi `.php` fajl pod `public/` vraća 404.

**Lozinke ne putuju uz objekte.** `Integration` namerno ne sadrži lozinku; za nju postoji zaseban poziv `IntegrationRepository::password()`, koji je dešifruje tek u trenutku prijave na API. Tako ne može da završi u `var_dump`-u, ispisu izuzetka ili logu.

**`Client/` je odvojen od `Collector/` i `Mapper/`.** Klijent zna samo kako da pozove API, kolektor kako da obiđe inventar, maper kako da prevede odgovor. Zahvaljujući tome će SOAP klijent za hardver hostova biti **nova klasa koja ispunjava isti ugovor** — kolektori i maperi se neće dirati.

**Upis je napisan jednom za sva tri tipa.** Sve tri DTO klase ispunjavaju `InventoryDto`, pa `InventoryRepository` radi sa `comparable()` i `hash()` i ne zna da li je u pitanju klaster, host ili mašina.

**`Source/` je pripremljen za više izvora.** Kasniji izvori (Kaspersky Security Center, čitanje iz CMDB baze) dodaju se pored `VCenter/`, a ne kao paralelni sistem.

---

## Migracije baze

Šema se razvija kroz numerisane SQL fajlove u `db/migrations/`. Migrator vodi evidenciju u tabeli `schema_migrations` i primenjuje samo ono što nedostaje.

- **Primenjena migracija se ne menja.** Ispravka ide kao nova verzija. Migrator čuva SHA-256 svakog fajla i odbija da radi ako se sadržaj primenjene migracije promenio — izlazi sa kodom `2` i objašnjava šta se desilo.
- **Jedna logička izmena po fajlu.** MySQL ne povlači DDL unazad; ako fajl sa više naredbi pukne na trećoj, prve dve ostaju primenjene.
- **Nema `down` migracija.** Rollback na produkciji je vraćanje backup-a, ne pokretanje skripte koja nikad nije testirana.

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
| `payload_hash` | SHA-256 nad **normalizovanim poljima**, ne nad sirovim odgovorom |

Pri svakom run-u: ako je `payload_hash` nepromenjen, osvežavaju se samo `last_seen` i `last_run_id`. Ako se promenio, red se ažurira i upisuje se zapis u `*_change` tabelu, sa **samo onim poljima koja su se stvarno promenila**.

Hash se računa nad normalizovanim poljima, a ne nad sirovim JSON-om, zato što bi svako polje koje vCenter vrati a SyncOPS ga ne čuva pravilo lažnu promenu u journalu.

Dalja podela posla: da li se nešto promenilo odlučuje **isključivo hash** (računa se na strani DTO-a, pa je pouzdan bez obzira na to kako MySQL vraća tipove); poređenje polja po koloni služi samo da se journal popuni razlikom. Ako to poređenje omane, u journalu stoji napomena — ali se promena koje nema ne može izmisliti.

Tabela `discovery_run` beleži svaki run sa statusom (`queued`, `running`, `success`, `partial`, `failed`) i brojačima — i to **posebno po tipu objekta**, pa `partial` ima upotrebljivo značenje: vidi se da su mašine prošle a hostovi pali.

Dva istovremena run-a nad istom integracijom sprečava sama baza: generisana kolona `active_lock` drži `integration_id` samo dok je run `queued` ili `running`, a nad njom stoji `UNIQUE` indeks.

### Identifikatori

Za svaki VM se povlače i čuvaju:

- `instance_uuid` — jedinstven u okviru vCentera, stabilan; primarni identifikator
- `bios_uuid` — SMBIOS UUID koji vidi gostujući OS; služi za korelaciju sa agentima. **Nije jedinstven** — klonovi i restore ga umeju ponoviti
- `moref` — identifikator u okviru vCentera (`vm-1234`); nije stabilan, koristi se samo unutar run-a i za dijagnostiku

Ako VM pređe u drugi vCenter, nastaje nov red. Prepoznavanje da je reč o istoj mašini je zadatak kasnije faze.

> **`guest_hostname` se ne sme koristiti kao identifikator.** U laboratoriji su tri mašine prijavile `localhost`, `bootstrap` i `system` — gosti su loše konfigurisani, a vCenter samo prenosi ono što dobije. Korelacija ide preko UUID-a.

---

## Poznata ograničenja vSphere REST API-ja

Ugrađena u dizajn, nisu greške u implementaciji:

**Limit po pozivu.** `GET /api/vcenter/vm` vraća najviše 4000 VM-ova (1000 u starijim verzijama), a preko toga vraća grešku umesto rezultata. Paginacije nema. Kolektor zato particioniše upit po klasteru i deduplikuje rezultat po `moref`-u.

**Host podaci.** REST za hostove daje samo ime, `connection_state` i `power_state`. Hardverski podaci — proizvođač, model, serijski broj, CPU, RAM, ESXi build — **nisu dostupni**. Kolone postoje u `vc_host` kao `NULL`, a `hardware_source` razlikuje „nije prikupljeno" od „prikupljeno pa prazno". Popunjavaju se SOAP klijentom.

**Templejti se ne vraćaju.** `/vcenter/vm` ih uopšte ne prikazuje, pa `is_template` ostaje `0`. Nema zaobilaznice kroz ovaj dijalekt.

**Identitet VM-a.** Blok `identity` (`instance_uuid`, `bios_uuid`) postoji od vSphere API 6.7.1 i dostupan je samo iz detalja po VM-u, ne iz liste. Discovery zato pravi dodatni poziv po mašini.

**Podaci iz gosta.** Hostname i IP dolaze iz `guest/identity` — još jedan poziv po mašini, i to samo dok VMware Tools rade i mašina je uključena. Kolona `guest_state` beleži razlog kada podaci izostanu. Ugašene mašine se ne pitaju uopšte.

**HTTP 503 nije privremena greška.** `guest/identity` vraća 503 kada Tools ne rade — to je trajno stanje mašine, ne preopterećenje servera. Transport ima listu statusa koji se ne ponavljaju; bez toga se svaka takva mašina pozivala tri puta uz pauze, što je na 153 mašine značilo 281 sekundu umesto 10.

**Dva API dijalekta.** vSphere 6.x–7.0 koristi `/rest/` putanje i login preko `/rest/com/vmware/cis/session`, dok 7.0U2+ koristi `/api/` i `/api/session`. Dijalekt se bira po polju `api_flavor`, uz automatsku detekciju pri prvom povezivanju.

---

## Zahtevi na strani vCentera

- servisni nalog sa rolom **Read-only** na root objektu, **sa propagacijom**
- nikakve druge privilegije nisu potrebne
- provera TLS sertifikata je podesiva po integraciji, jer se u praksi koriste self-signed sertifikati
- SyncOPS zatvara sesiju nakon svakog run-a, da ne bi iscrpeo limit konkurentnih sesija

---

## Roadmap

- [x] Razvojno okruženje: Docker Compose (nginx, PHP-FPM, MySQL, worker)
- [x] Migracije baze i šema: integracije, run-ovi, raw sloj, inventory sa journalom
- [x] Osnovni sloj: PDO, enkripcija kredencijala, CLI entrypoint
- [x] Web GUI: prijava, pregled, upravljanje integracijama
- [x] vCenter REST klijent, oba dijalekta, sa detekcijom verzije i proverom konekcije
- [x] Cluster / Host / VM discovery, raw + inventory sloj, journal promena
- [x] CLI worker i red čekanja; pokretanje prikupljanja iz GUI-ja
- [x] Pregled prikupljenih podataka u GUI-ju, sa pretragom i filterima
- [x] Izvoz u CSV
- [ ] SOAP klijent za hardverske podatke o hostovima (serijski broj, model, CPU, RAM)
- [ ] Sloj transformacija: korelacija objekata između vCentera
- [ ] Relacije između objekata (VM → Host → Cluster, VM → Datastore)
- [ ] Sinhronizacija prema CMDB bazi
- [ ] Integracija sa Kaspersky Security Center (radne stanice)

---

## Autor

Alen Nikolić
