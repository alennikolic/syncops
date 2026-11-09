<?php
// proba-vmk.php
//
// JEDNOKRATNA PROBA — obrisati posle merenja.
//
// Ispisuje PUN oblik jednog VMkernel adaptera, jednog NIC-a i po jednog HBA
// svake podvrste. Prethodna proba je pokazala samo prvi nivo polja, pa se
// nije videlo sta tacno stoji u spec bloku — a tu su IP adrese.
//
//   docker compose exec app php /app/proba-vmk.php <id-integracije>

declare(strict_types=1);

$root = __DIR__;
$composer = $root . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $c) use ($root): void {
        if (!str_starts_with($c, 'SyncOps\\')) { return; }
        $f = $root . '/src/' . str_replace('\\', '/', substr($c, 8)) . '.php';
        if (is_file($f)) { require $f; }
    });
}

use SyncOps\Core\Db;
use SyncOps\Core\Secret;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\Client\Soap\SoapTransport;

$id = (int) ($argv[1] ?? 0);

if ($id <= 0) {
    fwrite(STDERR, "Upotreba: php /app/proba-vmk.php <id-integracije>\n");
    exit(1);
}

$config      = require $root . '/config/config.php';
$repo        = new IntegrationRepository(Db::connect($config['db']), new Secret($config['secret_key']));
$integration = $repo->find($id);

if ($integration === null) {
    fwrite(STDERR, "Integracija {$id} ne postoji.\n");
    exit(1);
}

$http = new SoapTransport(
    baseUrl: $integration->baseUrl(),
    verifyPeer: $integration->verifyPeer,
    host: $integration->hostname,
    timeout: 180,
);

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$xp = static function (string $xml): DOMXPath {
    $d = new DOMDocument();
    $d->preserveWhiteSpace = false;
    $d->formatOutput = true;
    @$d->loadXML($xml);

    return new DOMXPath($d);
};

$pick = static function (DOMXPath $x, string $q): ?string {
    $n = $x->query($q);

    return ($n === false || $n->length === 0) ? null : trim((string) $n->item(0)?->textContent);
};

$x = $xp($http->call('RetrieveServiceContent',
    '<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'));

$pc   = $pick($x, '//*[local-name()="propertyCollector"]');
$vm   = $pick($x, '//*[local-name()="viewManager"]');
$root_= $pick($x, '//*[local-name()="rootFolder"]');
$sm   = $pick($x, '//*[local-name()="sessionManager"]');

$http->setApiVersion($pick($x, '//*[local-name()="about"]/*[local-name()="apiVersion"]'));
$http->call('Login', sprintf('<Login xmlns="urn:vim25"><_this type="SessionManager">%s</_this><userName>%s</userName><password>%s</password></Login>',
    $esc((string) $sm), $esc($integration->username), $esc($repo->password($id))));

$view = null;

try {
    $view = $pick($xp($http->call('CreateContainerView', sprintf(
        '<CreateContainerView xmlns="urn:vim25"><_this type="ViewManager">%s</_this>'
        . '<container type="Folder">%s</container><type>HostSystem</type><recursive>true</recursive></CreateContainerView>',
        $esc((string) $vm), $esc((string) $root_)))), '//*[local-name()="returnval"]');

    $x = $xp($http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25"><_this type="PropertyCollector">%s</_this>'
        . '<specSet><propSet><type>HostSystem</type>'
        . '<pathSet>config.network.vnic</pathSet><pathSet>config.network.pnic</pathSet>'
        . '<pathSet>config.storageDevice.hostBusAdapter</pathSet></propSet>'
        . '<objectSet><obj type="ContainerView">%s</obj><skip>true</skip>'
        . '<selectSet xsi:type="TraversalSpec"><name>v</name><type>ContainerView</type><path>view</path><skip>false</skip></selectSet>'
        . '</objectSet></specSet><options /></RetrievePropertiesEx>',
        $esc((string) $pc), $esc((string) $view))));

    /** Ispisi jedan primerak, po podvrsti. */
    $prikazi = static function (string $naslov, string $polje, int $koliko) use ($x): void {
        echo str_repeat('=', 78) . "\n{$naslov}\n" . str_repeat('=', 78) . "\n";

        $vidjeno = [];
        $n = 0;

        $putanja = '//*[local-name()="propSet"][*[local-name()="name"]="' . $polje . '"]'
            . '/*[local-name()="val"]/*';

        foreach ($x->query($putanja) ?: [] as $el) {
            $tip = $el instanceof DOMElement
                ? ($el->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') ?: $el->localName)
                : $el->localName;

            // Po jedan primerak svake podvrste.
            if (isset($vidjeno[$tip]) || $n >= $koliko) {
                continue;
            }

            $vidjeno[$tip] = true;
            $n++;

            echo "--- {$tip} ---\n";
            echo $el->C14N(), "\n\n";
        }
    };

    $prikazi('VMkernel adapter (tu su IP adrese)', 'config.network.vnic', 2);
    $prikazi('Fizicka mrezna kartica', 'config.network.pnic', 1);
    $prikazi('HBA — po jedan svake podvrste', 'config.storageDevice.hostBusAdapter', 4);
} finally {
    if ($view !== null) {
        $http->call('DestroyView', sprintf('<DestroyView xmlns="urn:vim25"><_this type="ContainerView">%s</_this></DestroyView>', $esc($view)));
    }

    $http->call('Logout', sprintf('<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>', $esc((string) $sm)));
}
