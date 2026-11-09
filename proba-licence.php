<?php
// proba-licence.php
//
// JEDNOKRATNA PROBA — ne pripada aplikaciji, obrisati posle merenja.
//
// Pita vCenter za listu licenci i ispisuje sirov odgovor. Postoji da bi se
// pre pisanja koda utvrdilo troje:
//
//   1. da li nalog integracije uopste SME da cita licence
//      (trazi se pravo Global.Licenses; read-only nalog ga obicno nema)
//   2. da li vCenter vraca pun kljuc ili maskiran (XXXXX-XXXXX-...)
//   3. koja polja stvarno stizu, da se tabela pravi po njima a ne po
//      dokumentaciji
//
// Upotreba:
//   docker compose exec app php /app/proba-licence.php <id-integracije>

declare(strict_types=1);

$root = __DIR__;

// Isti bootstrap kao bin/syncops: Composer ako postoji, inace PSR-4.
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
    fwrite(STDERR, "Upotreba: php /app/proba-licence.php <id-integracije>\n");
    exit(1);
}

// config.php cita env promenljive iz okruzenja kontejnera — nema rucnog
// parsiranja .env fajla, isto kao u bin/syncops.
$config = require $root . '/config/config.php';

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
    timeout:    60,
);

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$xpathOf = static function (string $xml): DOMXPath {
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput       = true;
    @$doc->loadXML($xml);

    return new DOMXPath($doc);
};

$pick = static function (DOMXPath $xpath, string $query, ?DOMNode $ctx = null): ?string {
    $n = $ctx === null ? $xpath->query($query) : $xpath->query($query, $ctx);

    if ($n === false || $n->length === 0) {
        return null;
    }

    $v = trim((string) $n->item(0)?->textContent);

    return $v === '' ? null : $v;
};

// -----------------------------------------------------------------------
// 1. ServiceContent — odatle se saznaje moref LicenseManager-a
// -----------------------------------------------------------------------
$xpath = $xpathOf($http->call(
    'RetrieveServiceContent',
    '<RetrieveServiceContent xmlns="urn:vim25">'
    . '<_this type="ServiceInstance">ServiceInstance</_this>'
    . '</RetrieveServiceContent>'
));

$collector = $pick($xpath, '//*[local-name()="propertyCollector"]');
$manager   = $pick($xpath, '//*[local-name()="licenseManager"]');
$session   = $pick($xpath, '//*[local-name()="sessionManager"]');

// SOAPAction mora da nosi verziju API-ja, inace vCenter za novije metode
// javlja da ne postoje.
$http->setApiVersion($pick($xpath, '//*[local-name()="about"]/*[local-name()="apiVersion"]'));

echo 'propertyCollector : ' . ($collector ?? '—') . "\n";
echo 'licenseManager    : ' . ($manager ?? 'NEMA GA U ServiceContent-u') . "\n\n";

if ($manager === null || $collector === null) {
    echo "vCenter nije prijavio licenseManager. Dalje merenje nije moguce.\n";
    exit(1);
}

// -----------------------------------------------------------------------
// 2. Prijava
// -----------------------------------------------------------------------
$http->call('Login', sprintf(
    '<Login xmlns="urn:vim25">'
    . '<_this type="SessionManager">%s</_this>'
    . '<userName>%s</userName><password>%s</password>'
    . '</Login>',
    $esc($session ?? 'SessionManager'),
    $esc($integration->username),
    $esc($repo->password($id))
));

try {
    // -------------------------------------------------------------------
    // 3. Licence
    //
    // Bez ContainerView: LicenseManager je jedan jedini objekat i cita se
    // direktno, za razliku od masina i hostova kojih ima mnogo.
    // -------------------------------------------------------------------
    $raw = $http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25">'
        . '<_this type="PropertyCollector">%s</_this>'
        . '<specSet>'
        . '<propSet><type>LicenseManager</type><pathSet>licenses</pathSet></propSet>'
        . '<objectSet><obj type="LicenseManager">%s</obj><skip>false</skip></objectSet>'
        . '</specSet>'
        . '<options />'
        . '</RetrievePropertiesEx>',
        $esc($collector),
        $esc($manager)
    ));

    $xpath    = $xpathOf($raw);
    $licenses = $xpath->query('//*[local-name()="val"]/*[local-name()="LicenseManagerLicenseInfo"]');
    $broj     = $licenses === false ? 0 : $licenses->length;

    echo "licenci u odgovoru: {$broj}\n";
    echo str_repeat('-', 70) . "\n";

    if ($broj === 0) {
        echo "Prazna lista. To obicno znaci da nalog nema pravo Global.Licenses.\n\n";
    }

    foreach ($licenses ?? [] as $i => $lic) {
        $get = static fn (string $tag): string
            => $pick($xpath, './*[local-name()="' . $tag . '"]', $lic) ?? '—';

        $kljuc    = $get('licenseKey');
        $maskiran = str_contains($kljuc, 'XXXXX');

        echo sprintf("[%d] %s\n", $i + 1, $get('name'));
        echo "     licenseKey  : {$kljuc}" . ($maskiran ? '   <-- MASKIRAN' : '') . "\n";
        echo '     editionKey  : ' . $get('editionKey') . "\n";
        echo '     total / used: ' . $get('total') . ' / ' . $get('used') . "\n";
        echo '     costUnit    : ' . $get('costUnit') . "\n";

        foreach ($xpath->query('./*[local-name()="properties"]', $lic) ?: [] as $p) {
            $k = $pick($xpath, './*[local-name()="key"]', $p) ?? '';
            $v = $pick($xpath, './*[local-name()="value"]', $p) ?? '';

            // feature.* ume da bude pedeset redova po licenci; ne zanima nas.
            if ($k !== '' && !str_starts_with($k, 'feature.')) {
                echo sprintf("     %-14s: %s\n", $k, $v);
            }
        }

        echo "\n";
    }

    echo str_repeat('=', 70) . "\n";
    echo "SIROV XML ODGOVOR\n";
    echo str_repeat('=', 70) . "\n";
    echo $xpath->document->saveXML();
} finally {
    $http->call('Logout', sprintf(
        '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
        $esc($session ?? 'SessionManager')
    ));
}
