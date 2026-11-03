# syncops
syncops/
├── .github/
│   └── workflows/ci.yml
├── docker/
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   └── nginx/
│       └── default.conf
├── docker-compose.yml
├── .env.example
├── .gitignore
├── composer.json
├── README.md
│
├── bin/
│   ├── syncops                  # jedini CLI entrypoint
│   └── migrate                  # pokretanje migracija
│
├── config/
│   └── config.php               # čita iz env, vraća niz
│
├── db/
│   └── migrations/
│       ├── 0001_integration.sql
│       ├── 0002_discovery_run.sql
│       ├── 0003_raw_object.sql
│       └── 0004_vcenter_inventory.sql
│
├── public/                      # JEDINO ovo je document root
│   ├── index.php                # front controller
│   └── assets/
│
├── src/
│   ├── Core/
│   │   ├── Db.php               # PDO wrapper
│   │   ├── Secret.php           # sodium enkripcija kredencijala
│   │   ├── Log.php              # JSON linije na stdout
│   │   └── Clock.php
│   │
│   ├── Run/
│   │   ├── DiscoveryRunner.php  # orkestracija: lock, run zapis, status
│   │   ├── RunRepository.php
│   │   └── RunStatus.php
│   │
│   ├── Source/
│   │   ├── SourceInterface.php          # ugovor za svaki izvor
│   │   ├── Dto/
│   │   │   ├── ClusterDto.php
│   │   │   ├── HostDto.php
│   │   │   └── VmDto.php
│   │   └── VCenter/
│   │       ├── VCenterSource.php
│   │       ├── Client/
│   │       │   ├── ClientInterface.php
│   │       │   ├── RestLegacyClient.php   # /rest  (6.7–7.0)
│   │       │   ├── RestClient.php         # /api   (7.0U2+)
│   │       │   ├── FlavorDetector.php
│   │       │   └── HttpTransport.php      # curl, curl_multi, retry
│   │       ├── Collector/
│   │       │   ├── ClusterCollector.php
│   │       │   ├── HostCollector.php
│   │       │   └── VmCollector.php        # particionisanje zbog limita 4000
│   │       └── Mapper/
│   │           ├── HostMapper.php         # JSON -> HostDto
│   │           └── VmMapper.php
│   │
│   ├── Storage/
│   │   ├── RawStore.php                   # sirovi JSON po objektu po run-u
│   │   ├── ChangeJournal.php              # payload_hash + *_change upis
│   │   └── Repository/
│   │       ├── ClusterRepository.php
│   │       ├── HostRepository.php
│   │       └── VmRepository.php
│   │
│   ├── Integration/
│   │   ├── Integration.php
│   │   └── IntegrationRepository.php
│   │
│   └── Web/
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
│       └── vcenter/                       # snimljeni pravi JSON odgovori
│           ├── api/vm-list.json
│           ├── api/vm-detail.json
│           └── rest/host-list.json
│
└── var/                                   # gitignore
    └── log/
