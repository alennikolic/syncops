<?php
// proba-datastore.php
//
// JEDNOKRATNA PROBA — ne pripada aplikaciji, obrisati posle merenja.
//
// Pita vCenter za datastore-ove i ispisuje sta je stvarno stiglo. Postoji da
// bi se pre pisanja tabele utvrdilo:
//
//   1. koja polja dolaze, a koja izostanu po tipu (VMFS, NFS, vSAN, VVOL)
//   2. kako izgleda spisak hostova koji montiraju datastore
//   3. koliko je odgovor veliki — isti problem kao kod licenci, gde je
//      sirov XML bio 221 KB za devet stavki
//
// Za razliku od licenci, datastore JESTE objekat inventara i ima moref, pa se
// cita kroz ContainerView, isto kao hostovi i klasteri.
//
// Upotreba:
//   docker compose exec app php /app/proba-datastore.php <id-integracije>

declare(strict_types=1);

$root = __DIR__;

$composer = $root . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'SyncOps\\')) {
            return;
        }

        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

use SyncOps\Core\Db;
use SyncOps\Core\Secret;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\Client\Soap\SoapTransport;

$id = (int) ($argv[1] ?? 0);

if ($id <= 0) {
    fwrite(STDERR, "Upotreba: php /app/proba-datastore.php <id-integracije>\n");
    exit(1);
}

$config      = require $root . '/config/config.php';
$pdo         = Db::connect($config['db']);
$repo        = new IntegrationRepository($pdo, new Secret($config['secret_key']));
$integration = $repo->find($id);

if ($integration === null) {
    fwrite(STDERR, "Integracija {$id} ne postoji.\n");
    exit(1);
}

echo "vCenter: {$integration->baseUrl()}  nalog: {$integration->username}\n\n";

$http = new SoapTransport(
    baseUrl:    $integration->baseUrl(),
    verifyPeer: $integration->verifyPeer,
    host:       $integration->hostname,
    timeout:    120,
);

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$xpathOf = static function (string $xml): DOMXPath {
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput       = true;
    @$doc->loadXML($xml);

    return new DOMXPath($doc);
};

$pick = static function (DOMXPath $x, string $q, ?DOMNode $c = null): ?string {
    $n = $c === null ? $x->query($q) : $x->query($q, $c);

    if ($n === false || $n->length === 0) {
        return null;
    }

    $v = trim((string) $n->item(0)?->textContent);

    return $v === '' ? null : $v;
};

/** Bajtovi u citljiv oblik, samo za ispis ove probe. */
$gib = static function (?string $bytes): string {
    if ($bytes === null || !is_numeric($bytes)) {
        return '—';
    }

    return number_format((float) $bytes / 1073741824, 1, ',', '.') . ' GiB';
};

// -----------------------------------------------------------------------
// 1. ServiceContent + prijava
// -----------------------------------------------------------------------
$x = $xpathOf($http->call(
    'RetrieveServiceContent',
    '<RetrieveServiceContent xmlns="urn:vim25">'
    . '<_this type="ServiceInstance">ServiceInstance</_this>'
    . '</RetrieveServiceContent>'
));

$collector = $pick($x, '//*[local-name()="propertyCollector"]');
$viewMgr   = $pick($x, '//*[local-name()="viewManager"]');
$rootF     = $pick($x, '//*[local-name()="rootFolder"]');
$session   = $pick($x, '//*[local-name()="sessionManager"]');

$http->setApiVersion($pick($x, '//*[local-name()="about"]/*[local-name()="apiVersion"]'));

$http->call('Login', sprintf(
    '<Login xmlns="urn:vim25"><_this type="SessionManager">%s</_this>'
    . '<userName>%s</userName><password>%s</password></Login>',
    $esc($session ?? 'SessionManager'),
    $esc($integration->username),
    $esc($repo->password($id))
));

$view = null;

try {
    // -------------------------------------------------------------------
    // 2. Pogled na sve datastore-ove
    // -------------------------------------------------------------------
    $view = $pick($xpathOf($http->call('CreateContainerView', sprintf(
        '<CreateContainerView xmlns="urn:vim25">'
        . '<_this type="ViewManager">%s</_this>'
        . '<container type="Folder">%s</container>'
        . '<type>Datastore</type><recursive>true</recursive>'
        . '</CreateContainerView>',
        $esc($viewMgr ?? 'ViewManager'),
        $esc((string) $rootF)
    ))), '//*[local-name()="returnval"]');

    if ($view === null) {
        echo "vCenter nije vratio pogled na datastore-ove.\n";
        exit(1);
    }

    // Trazi se i info: tu su VMFS detalji (uuid, verzija, ekstenti) odnosno
    // NFS detalji (remoteHost, remotePath). Deo merenja je i koliko to
    // nadima odgovor — ako je skupo, u kod ide suzen spisak.
    $polja = [
        'name',
        'summary.type',
        'summary.capacity',
        'summary.freeSpace',
        'summary.uncommitted',
        'summary.accessible',
        'summary.multipleHostAccess',
        'summary.maintenanceMode',
        'summary.url',
        'overallStatus',
        'host',
        'info',
    ];

    $pathSet = '';

    foreach ($polja as $p) {
        $pathSet .= '<pathSet>' . $esc($p) . '</pathSet>';
    }

    $raw = $http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25">'
        . '<_this type="PropertyCollector">%s</_this>'
        . '<specSet>'
        . '<propSet><type>Datastore</type>%s</propSet>'
        . '<objectSet><obj type="ContainerView">%s</obj><skip>true</skip>'
        . '<selectSet xsi:type="TraversalSpec">'
        . '<name>viewToObject</name><type>ContainerView</type><path>view</path><skip>false</skip>'
        . '</selectSet>'
        . '</objectSet>'
        . '</specSet>'
        . '<options />'
        . '</RetrievePropertiesEx>',
        $esc((string) $collector),
        $pathSet,
        $esc($view)
    ));

    $x = $xpathOf($raw);

    $objekti = $x->query('//*[local-name()="objects"]');
    $broj    = $objekti === false ? 0 : $objekti->length;

    echo "datastore-ova: {$broj}\n";
    echo 'velicina odgovora: ' . number_format(strlen($raw), 0, ',', '.') . " B\n";
    echo str_repeat('-', 78) . "\n\n";

    /** @var array<string,int> $poTipu */
    $poTipu = [];
    $svaPolja = [];

    foreach ($objekti ?: [] as $obj) {
        $moref = $pick($x, './*[local-name()="obj"]', $obj) ?? '?';

        // Polja po imenu.
        $props = [];

        foreach ($x->query('./*[local-name()="propSet"]', $obj) ?: [] as $ps) {
            $ime = $pick($x, './*[local-name()="name"]', $ps);

            if ($ime !== null) {
                $props[$ime] = $ps;
                $svaPolja[$ime] = ($svaPolja[$ime] ?? 0) + 1;
            }
        }

        $val = static fn (string $ime): ?string
            => isset($props[$ime]) ? $pick($x, './*[local-name()="val"]', $props[$ime]) : null;

        $tip = $val('summary.type') ?? '?';
        $poTipu[$tip] = ($poTipu[$tip] ?? 0) + 1;

        $kapacitet = $val('summary.capacity');
        $slobodno  = $val('summary.freeSpace');

        printf("[%s] %s\n", $moref, $val('name') ?? '?');
        printf("     tip        : %s   status: %s\n", $tip, $val('overallStatus') ?? '—');
        printf("     kapacitet  : %s   slobodno: %s   neupisano: %s\n",
            $gib($kapacitet), $gib($slobodno), $gib($val('summary.uncommitted')));
        printf("     dostupan   : %s   vise hostova: %s   maintenance: %s\n",
            $val('summary.accessible') ?? '—',
            $val('summary.multipleHostAccess') ?? '—',
            $val('summary.maintenanceMode') ?? '—');
        printf("     url        : %s\n", $val('summary.url') ?? '—');

        // Hostovi koji ga montiraju.
        if (isset($props['host'])) {
            $mounts = $x->query('./*[local-name()="val"]/*', $props['host']);
            $imena  = [];

            foreach ($mounts ?: [] as $m) {
                $h = $pick($x, './*[local-name()="key"]', $m);

                if ($h !== null) {
                    $imena[] = $h;
                }
            }

            printf("     hostova    : %d  (%s)\n", count($imena),
                implode(', ', array_slice($imena, 0, 4)) . (count($imena) > 4 ? ', …' : ''));

            // Oblik jednog mount zapisa — zanima nas ima li tu jos cega.
            if ($mounts !== false && $mounts->length > 0) {
                $prvi = $mounts->item(0);
                $deca = [];

                foreach ($prvi?->childNodes ?? [] as $c) {
                    if ($c->nodeType === XML_ELEMENT_NODE) {
                        $deca[] = $c->localName;
                    }
                }

                printf("     mount polja: %s\n", implode(', ', $deca));
            }
        }

        // info: razlikuje se po tipu datastore-a
        if (isset($props['info'])) {
            $infoVal = $x->query('./*[local-name()="val"]', $props['info'])->item(0);
            $tipInfo = $infoVal?->attributes?->getNamedItem('type')?->nodeValue
                ?? $infoVal?->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')
                ?? '?';

            $deca = [];

            foreach ($infoVal?->childNodes ?? [] as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $deca[] = $c->localName;
                }
            }

            printf("     info (%s): %s\n", $tipInfo, implode(', ', $deca));
            printf("     info bajtova: %d\n", strlen((string) $infoVal?->C14N()));
        }

        echo "\n";
    }

    echo str_repeat('=', 78) . "\n";
    echo "SAZETAK\n";
    echo str_repeat('=', 78) . "\n";
    echo "po tipu: ";

    foreach ($poTipu as $t => $n) {
        echo "{$t}={$n}  ";
    }

    echo "\n\npolja koja su stigla (od " . $broj . " objekata):\n";

    foreach ($polja as $p) {
        printf("  %-30s %s\n", $p, isset($svaPolja[$p]) ? $svaPolja[$p] . 'x' : 'NIJE STIGLO');
    }
} finally {
    if ($view !== null) {
        $http->call('DestroyView', sprintf(
            '<DestroyView xmlns="urn:vim25"><_this type="ContainerView">%s</_this></DestroyView>',
            $esc($view)
        ));
    }

    $http->call('Logout', sprintf(
        '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
        $esc($session ?? 'SessionManager')
    ));
}
