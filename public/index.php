<?php
// public/index.php
//
// Front controller. Nginx preusmerava sve zahteve ovamo (osim postojecih
// fajlova pod public/), pa je ovo jedina PHP tacka ulaza za web.

declare(strict_types=1);

$root = dirname(__DIR__);

// -----------------------------------------------------------------------
// Autoload — isto kao u bin/syncops: Composer ako postoji, inace PSR-4.
// -----------------------------------------------------------------------
$composerAutoload = $root . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'SyncOps\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = $root . '/src/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

use SyncOps\Core\Db;
use SyncOps\Core\Secret;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\Client\ClientFactory;
use SyncOps\Integration\IntegrationType;
use SyncOps\Source\AssetOps\ClientFactory as AssetOpsClientFactory;
use SyncOps\Source\AssetOps\ConnectionTester as AssetOpsTester;
use SyncOps\Source\VCenter\ConnectionTester as VCenterTester;
use SyncOps\Web\Auth;
use SyncOps\Web\Controller\AssetOpsController;
use SyncOps\Web\Controller\AuthController;
use SyncOps\Web\Controller\DashboardController;
use SyncOps\Web\Controller\DatabaseController;
use SyncOps\Web\Controller\IntegrationController;
use SyncOps\Web\Controller\InventoryController;
use SyncOps\Web\Controller\RunController;
use SyncOps\Run\DiscoveryRunner;
use SyncOps\Run\RunRepository;
use SyncOps\Source\VCenter\VCenterSource;
use SyncOps\Storage\AssetOpsRepository;
use SyncOps\Storage\InventoryQuery;
use SyncOps\Storage\LinkRepository;
use SyncOps\Storage\SyncActionRepository;
use SyncOps\Storage\Maintenance;
use SyncOps\Storage\RawStore;
use SyncOps\Storage\SchemaQuery;
use SyncOps\Storage\Repository\ClusterRepository;
use SyncOps\Storage\Repository\HostRepository;
use SyncOps\Storage\Repository\VmRepository;
use SyncOps\Web\Flash;
use SyncOps\Web\Router;
use SyncOps\Web\View;

$config = require $root . '/config/config.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    // -------------------------------------------------------------------
    // Sastavljanje
    // -------------------------------------------------------------------
    $auth = new Auth($config['app']['password_hash']);
    $auth->start();

    $flash = new Flash();
    $view  = new View($root . '/src/Web/View');

    // Layout ih ocekuje na svakoj stranici.
    //
    // assetVersion visi uz putanju stila da bi izmena CSS-a stigla do
    // pregledaca odmah: nginx servira /assets/ sa "expires 7d", pa bi se bez
    // ovoga koristila kesirana verzija jos nedelju dana.
    $view->share('assetVersion', (string) (@filemtime(__DIR__ . '/assets/app.css') ?: '1'));
    $view->share('csrfGlobal', $auth->csrfToken());
    $view->share('flash', null);

    $router     = new Router();
    $authCtrl   = new AuthController($auth, $view, $flash);
    $path       = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method     = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $router->get('/login', $authCtrl->loginForm(...));
    $router->post('/login', $authCtrl->login(...));
    $router->post('/logout', $authCtrl->logout(...));

    // -------------------------------------------------------------------
    // Sve ostalo zahteva prijavu
    // -------------------------------------------------------------------
    $isPublic = in_array($path, ['/login', '/logout'], true);

    if (!$isPublic && !$auth->check()) {
        header('Location: /login');
        exit;
    }

    if (!$isPublic) {
        // Poruka se cita tek ovde, da je stranica prijave ne pojede.
        $view->share('flash', $flash->take());

        $pdo   = Db::connect($config['db'], retries: 2);
        $repo  = new IntegrationRepository($pdo, new Secret($config['secret_key']));

        // Provera konekcije po tipu integracije. Mapa a ne grananje u
        // kontroleru: nov tip se dodaje ovde jednim redom.
        $testers = [
            IntegrationType::VCENTER  => new VCenterTester($repo, new ClientFactory()),
            IntegrationType::ASSETOPS => new AssetOpsTester($repo, new AssetOpsClientFactory()),
        ];

        // GUI samo upisuje posao u red; izvrsava ga worker.
        $runner = new DiscoveryRunner(
            $pdo,
            $repo,
            new RunRepository($pdo),
            new VCenterSource(new ClientFactory(timeout: 60)),
            new RawStore($pdo),
            new ClusterRepository($pdo),
            new HostRepository($pdo),
            new VmRepository($pdo),
        );

        $dashboard   = new DashboardController($pdo, $repo, $view);
        $integration = new IntegrationController($repo, $view, $auth, $flash, $testers, $runner);
        $inventoryQuery = new InventoryQuery($pdo);
        $inventory   = new InventoryController($inventoryQuery, $view);
        $runs        = new RunController($pdo, $view);
        $assetOps    = new AssetOpsController(
            $repo,
            new AssetOpsRepository($pdo),
            new LinkRepository($pdo),
            new SyncActionRepository($pdo),
            $inventoryQuery,
            $view,
            $auth,
            $flash,
            new AssetOpsClientFactory(),
        );

        $database    = new DatabaseController(
            new SchemaQuery($pdo),
            $view,
            new Maintenance($pdo),
            $auth,
            $flash,
        );

        $router->get('/', $dashboard->index(...));

        $router->get('/vms', $inventory->vms(...));
        $router->get('/vms/export', $inventory->exportVms(...));
        $router->get('/vms/{id}', $inventory->vm(...));
        $router->get('/hosts', $inventory->hosts(...));
        $router->get('/hosts/export', $inventory->exportHosts(...));
        $router->get('/hosts/{id}', $inventory->host(...));
        $router->get('/clusters', $inventory->clusters(...));

        // Imenovane /assetops/* rute moraju PRE ruta sa {id}, inace bi se
        // "mapping" procitalo kao identifikator integracije.
        $router->get('/assetops', $assetOps->index(...));
        $router->get('/assetops/ci', $assetOps->ciRedirect(...));
        $router->get('/assetops/match', $assetOps->match(...));
        $router->get('/assetops/plan', $assetOps->planView(...));
        $router->get('/assetops/mapping', $assetOps->mapping(...));
        $router->post('/assetops/{id}/refresh', $assetOps->refresh(...));
        $router->post('/assetops/{id}/mapping', $assetOps->saveMapping(...));
        $router->post('/assetops/{id}/clean', $assetOps->cleanReference(...));
        $router->post('/assetops/{id}/apply', $assetOps->applyPlan(...));
        $router->post('/assetops/{id}/match', $assetOps->saveLinks(...));
        $router->post('/assetops/{id}/unlink', $assetOps->unlink(...));
        $router->get('/runs', $runs->index(...));

        // Pregled baze. Cita samo — nema unosa SQL-a ni izmena.
        //
        // /db/reset mora PRE /db/{table}, inace bi ga ruter procitao kao
        // zahtev za tabelu imena "reset".
        $router->get('/db', $database->index(...));
        $router->get('/db/reset', $database->resetForm(...));
        $router->post('/db/reset', $database->reset(...));
        $router->get('/db/{table}', $database->table(...));
        $router->get('/db/{table}/{id}', $database->row(...));

        $router->get('/integrations', $integration->index(...));
        $router->get('/integrations/new', $integration->createForm(...));
        $router->get('/integrations/new/{type}', $integration->createForm(...));
        $router->post('/integrations', $integration->create(...));
        $router->get('/integrations/{id}/edit', $integration->editForm(...));
        $router->post('/integrations/{id}', $integration->update(...));
        $router->post('/integrations/{id}/test', $integration->test(...));
        $router->post('/integrations/{id}/discover', $integration->discover(...));
        $router->post('/integrations/{id}/state', $integration->toggle(...));
    }

    if (!$router->dispatch($method, $path)) {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="sr"><head><meta charset="utf-8">'
            . '<title>Stranica ne postoji · SyncOPS</title>'
            . '<link rel="stylesheet" href="/assets/app.css"></head><body>'
            . '<div class="login-shell"><div class="login">'
            . '<div class="brand">Sync<span>OPS</span></div>'
            . '<div class="panel"><p style="margin:0">Ta stranica ne postoji. '
            . '<a href="/">Nazad na pregled</a>.</p></div>'
            . '</div></div></body></html>';
    }
} catch (Throwable $e) {
    http_response_code(500);

    error_log('SyncOPS: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    $isDev   = ($config['env'] ?? 'dev') === 'dev';
    $details = $isDev
        ? '<pre class="data" style="white-space:pre-wrap">'
            . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES)
            . '</pre>'
        : '<p class="muted" style="margin:0">Detalji su u logu kontejnera: '
            . '<code class="data">docker compose logs app</code></p>';

    echo '<!DOCTYPE html><html lang="sr"><head><meta charset="utf-8">'
        . '<title>Greška · SyncOPS</title>'
        . '<link rel="stylesheet" href="/assets/app.css"></head><body>'
        . '<div class="login-shell"><div class="login" style="max-width:40rem">'
        . '<div class="brand">Sync<span>OPS</span></div>'
        . '<div class="panel"><p style="margin-top:0">Zahtev nije mogao da se obradi.</p>'
        . $details . '</div></div></div></body></html>';
}
