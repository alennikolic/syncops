<?php
// proba-host-uredjaji.php
//
// JEDNOKRATNA PROBA — ne pripada aplikaciji, obrisati posle merenja.
//
// Meri sta vCenter vraca za uredjaje hosta: fizicke mrezne kartice, VMkernel
// adaptere, standardne sviceve, port grupe i HBA.
//
// Tri stvari koje treba da se vide pre pisanja tabele:
//   1. koja polja stvarno stizu po vrsti uredjaja
//   2. koliko se odgovor za hostove uvecava — isti blok se cita i u punoj
//      listi i po datacentru, pa se cena mnozi
//   3. da li je ime uredjaja (vmnic0, vmk0, vmhba1) jedinstveno u okviru
//      hosta, jer od toga zavisi da li moze da bude deo kljuca
//
// Upotreba:
//   docker compose exec app php /app/proba-host-uredjaji.php <id-integracije>

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
    fwrite(STDERR, "Upotreba: php /app/proba-host-uredjaji.php <id-integracije>\n");
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
    timeout:    180,
);

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$xp = static function (string $xml): DOMXPath {
    $d = new DOMDocument();
    $d->preserveWhiteSpace = false;
    @$d->loadXML($xml);

    return new DOMXPath($d);
};

$pick = static function (DOMXPath $x, string $q, ?DOMNode $c = null): ?string {
    $n = $c === null ? $x->query($q) : $x->query($q, $c);

    if ($n === false || $n->length === 0) {
        return null;
    }

    $v = trim((string) $n->item(0)?->textContent);

    return $v === '' ? null : $v;
};

/** Imena elemenata-dece jednog cvora, radi uvida u oblik. */
$deca = static function (?DOMNode $n): array {
    $imena = [];

    foreach ($n?->childNodes ?? [] as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE) {
            $imena[$c->localName] = true;
        }
    }

    return array_keys($imena);
};

$x = $xp($http->call(
    'RetrieveServiceContent',
    '<RetrieveServiceContent xmlns="urn:vim25">'
    . '<_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'
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
    $view = $pick($xp($http->call('CreateContainerView', sprintf(
        '<CreateContainerView xmlns="urn:vim25">'
        . '<_this type="ViewManager">%s</_this>'
        . '<container type="Folder">%s</container>'
        . '<type>HostSystem</type><recursive>true</recursive></CreateContainerView>',
        $esc($viewMgr ?? 'ViewManager'),
        $esc((string) $rootF)
    ))), '//*[local-name()="returnval"]');

    // Prvo SAMO ime, da se zna koliko odgovor tezi bez novih polja.
    $osnovni = $http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25"><_this type="PropertyCollector">%s</_this>'
        . '<specSet><propSet><type>HostSystem</type><pathSet>name</pathSet></propSet>'
        . '<objectSet><obj type="ContainerView">%s</obj><skip>true</skip>'
        . '<selectSet xsi:type="TraversalSpec"><name>v</name><type>ContainerView</type>'
        . '<path>view</path><skip>false</skip></selectSet></objectSet></specSet>'
        . '<options /></RetrievePropertiesEx>',
        $esc((string) $collector),
        $esc((string) $view)
    ));

    $polja = [
        'name',
        'config.network.pnic',
        'config.network.vnic',
        'config.network.vswitch',
        'config.network.portgroup',
        'config.storageDevice.hostBusAdapter',
    ];

    $pathSet = '';

    foreach ($polja as $p) {
        $pathSet .= '<pathSet>' . $esc($p) . '</pathSet>';
    }

    $pun = $http->call('RetrievePropertiesEx', sprintf(
        '<RetrievePropertiesEx xmlns="urn:vim25"><_this type="PropertyCollector">%s</_this>'
        . '<specSet><propSet><type>HostSystem</type>%s</propSet>'
        . '<objectSet><obj type="ContainerView">%s</obj><skip>true</skip>'
        . '<selectSet xsi:type="TraversalSpec"><name>v</name><type>ContainerView</type>'
        . '<path>view</path><skip>false</skip></selectSet></objectSet></specSet>'
        . '<options /></RetrievePropertiesEx>',
        $esc((string) $collector),
        $pathSet,
        $esc((string) $view)
    ));

    $x        = $xp($pun);
    $objekti  = $x->query('//*[local-name()="objects"]');
    $brojHost = $objekti === false ? 0 : $objekti->length;

    printf("hostova: %d\n", $brojHost);
    printf("odgovor samo sa imenom : %s B\n", number_format(strlen($osnovni), 0, ',', '.'));
    printf("odgovor sa uredjajima  : %s B   (%.1fx vece)\n\n",
        number_format(strlen($pun), 0, ',', '.'),
        strlen($osnovni) > 0 ? strlen($pun) / strlen($osnovni) : 0);

    $vrste = [
        'config.network.pnic'                 => ['NIC', 'PhysicalNic'],
        'config.network.vnic'                 => ['VMkernel', 'HostVirtualNic'],
        'config.network.vswitch'              => ['vSwitch', 'HostVirtualSwitch'],
        'config.network.portgroup'            => ['Port grupa', 'HostPortGroup'],
        'config.storageDevice.hostBusAdapter' => ['HBA', null],
    ];

    /** @var array<string,array<string,bool>> $oblici */
    $oblici = [];
    $ukupno = [];

    foreach ($objekti ?: [] as $obj) {
        $moref = $pick($x, './*[local-name()="obj"]', $obj) ?? '?';
        $props = [];

        foreach ($x->query('./*[local-name()="propSet"]', $obj) ?: [] as $ps) {
            $ime = $pick($x, './*[local-name()="name"]', $ps);

            if ($ime !== null) {
                $props[$ime] = $ps;
            }
        }

        printf("[%s] %s\n", $moref, $pick($x, './*[local-name()="val"]', $props['name'] ?? null) ?? '?');

        foreach ($vrste as $polje => [$naziv, $_tip]) {
            if (!isset($props[$polje])) {
                printf("     %-11s NIJE STIGLO\n", $naziv);

                continue;
            }

            $stavke = $x->query('./*[local-name()="val"]/*', $props[$polje]);
            $n      = $stavke === false ? 0 : $stavke->length;
            $ukupno[$naziv] = ($ukupno[$naziv] ?? 0) + $n;

            // Imena uredjaja — provera da li su jedinstvena u okviru hosta.
            $imena = [];

            foreach ($stavke ?: [] as $s) {
                $ime = $pick($x, './*[local-name()="device"]', $s)
                    ?? $pick($x, './*[local-name()="name"]', $s)
                    ?? $pick($x, './*[local-name()="spec"]/*[local-name()="name"]', $s)
                    ?? $pick($x, './*[local-name()="key"]', $s);

                if ($ime !== null) {
                    $imena[] = $ime;
                }

                foreach ($deca($s) as $d) {
                    $oblici[$naziv][$d] = true;
                }

                // xsi:type kaze o kojoj je podvrsti rec (npr. HostFibreChannelHba)
                $tip = $s instanceof DOMElement
                    ? $s->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')
                    : '';

                if ($tip !== '') {
                    $oblici[$naziv]['@' . $tip] = true;
                }
            }

            $jedinstvena = count($imena) === count(array_unique($imena)) ? '' : '  <-- IMENA SE PONAVLJAJU';

            printf("     %-11s %2d  %s%s\n", $naziv, $n,
                implode(', ', array_slice($imena, 0, 6)) . (count($imena) > 6 ? ', …' : ''),
                $jedinstvena);
        }

        echo "\n";
    }

    echo str_repeat('=', 78) . "\n";
    echo "UKUPNO PO VRSTI\n";
    echo str_repeat('=', 78) . "\n";

    foreach ($ukupno as $naziv => $n) {
        printf("  %-11s %d\n", $naziv, $n);
    }

    echo "\nPOLJA KOJA SE POJAVLJUJU (po vrsti)\n";
    echo str_repeat('-', 78) . "\n";

    foreach ($oblici as $naziv => $polja2) {
        printf("  %s:\n    %s\n\n", $naziv, implode(', ', array_keys($polja2)));
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
