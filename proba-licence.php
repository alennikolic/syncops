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

require __DIR__ . '/src/Core/Db.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'SyncOps\\')) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

use SyncOps\Core\Db;
use SyncOps\Core\Secret;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\Client\Soap\SoapTransport;

$id = (int) ($argv[1] ?? 0);

if ($id <= 0) {
    fwrite(STDERR, "Upotreba: php /app/proba-licence.php <id-integracije>\n");
    exit(1);
}

// .env se cita isto kao u bin/syncops.
foreach (['/app/.env', __DIR__ . '/.env'] as $env) {
    if (is_file($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (!str_starts_with(trim($line), '#') && str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $_ENV[trim($k)] = trim($v);
            }
        }
        break;
    }
}

$pdo         = (new Db())->pdo();
$repo        = new IntegrationRepository($pdo, new Secret());
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

$pick = static function (string $xml, string $tag): ?string {
    $doc = new DOMDocument();
    @$doc->loadXML($xml);
    $n = (new DOMXPath($doc))->query('//*[local-name()="' . $tag . '"]');

    return ($n === false || $n->length === 0) ? null : trim((string) $n->item(0)?->textContent);
};

// -----------------------------------------------------------------------
// 1. ServiceContent — odatle se saznaje moref LicenseManager-a
// -----------------------------------------------------------------------
$xml = $http->call(
    'RetrieveServiceContent',
    '<RetrieveServiceContent xmlns="urn:vim25">'
    . '<_this type="ServiceInstance">ServiceInstance</_this>'
    . '</RetrieveServiceContent>'
);

$collector = $pick($xml, 'propertyCollector');
$manager   = $pick($xml, 'licenseManager');
$session   = $pick($xml, 'sessionManager');

$doc = new DOMDocument();
@$doc->loadXML($xml);
$api = (new DOMXPath($doc))->query('//*[local-name()="about"]/*[local-name()="apiVersion"]');
$http->setApiVersion($api && $api->length ? trim((string) $api->item(0)?->textContent) : null);

echo "propertyCollector : " . ($collector ?? '—') . "\n";
echo "licenseManager    : " . ($manager ?? 'NEMA GA U ServiceContent-u') . "\n\n";

if ($manager === null) {
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
    $xml = $http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25">'
        . '<_this type="PropertyCollector">%s</_this>'
        . '<specSet>'
        . '<propSet><type>LicenseManager</type><pathSet>licenses</pathSet></propSet>'
        . '<objectSet><obj type="LicenseManager">%s</obj><skip>false</skip></objectSet>'
        . '</specSet>'
        . '<options />'
        . '</RetrievePropertiesEx>',
        $esc((string) $collector),
        $esc($manager)
    ));

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput       = true;
    @$doc->loadXML($xml);

    $xpath    = new DOMXPath($doc);
    $licenses = $xpath->query('//*[local-name()="val"]/*[local-name()="LicenseManagerLicenseInfo"]');
    $broj     = $licenses === false ? 0 : $licenses->length;

    echo "licenci u odgovoru: {$broj}\n";
    echo str_repeat('-', 70) . "\n";

    if ($broj === 0) {
        echo "Prazna lista. To obicno znaci da nalog nema pravo Global.Licenses.\n\n";
    }

    foreach ($licenses ?? [] as $i => $lic) {
        $get = static function (string $tag) use ($xpath, $lic): string {
            $n = $xpath->query('./*[local-name()="' . $tag . '"]', $lic);

            return ($n === false || $n->length === 0) ? '—' : trim((string) $n->item(0)?->textContent);
        };

        $kljuc    = $get('licenseKey');
        $maskiran = str_contains($kljuc, 'XXXXX');

        echo sprintf("[%d] %s\n", $i + 1, $get('name'));
        echo "     licenseKey  : {$kljuc}" . ($maskiran ? '   <-- MASKIRAN' : '') . "\n";
        echo "     editionKey  : " . $get('editionKey') . "\n";
        echo "     total / used: " . $get('total') . ' / ' . $get('used') . "\n";
        echo "     costUnit    : " . $get('costUnit') . "\n";

        foreach ($xpath->query('./*[local-name()="properties"]', $lic) ?: [] as $p) {
            $k = $xpath->query('./*[local-name()="key"]', $p);
            $v = $xpath->query('./*[local-name()="value"]', $p);
            $kt = ($k && $k->length) ? trim((string) $k->item(0)?->textContent) : '';
            $vt = ($v && $v->length) ? trim((string) $v->item(0)?->textContent) : '';

            if ($kt !== '' && !str_starts_with($kt, 'feature.')) {
                echo sprintf("     %-12s: %s\n", $kt, $vt);
            }
        }

        echo "\n";
    }

    echo str_repeat('=', 70) . "\n";
    echo "SIROV XML ODGOVOR\n";
    echo str_repeat('=', 70) . "\n";
    echo $doc->saveXML();
} finally {
    $http->call('Logout', sprintf(
        '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
        $esc($session ?? 'SessionManager')
    ));
}
