<?php
// public/index.php
//
// PRIVREMENA status stranica — sluzi samo da potvrdi da je okruzenje
// ispravno podignuto (PHP, ekstenzije, konekcija ka bazi).
//
// Bice zamenjena pravim front controllerom koji poziva
// SyncOps\Web\Router cim src/ bude popunjen.

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

// --- Provera ekstenzija ---------------------------------------------
$requiredExtensions = ['pdo_mysql', 'curl', 'sodium', 'json', 'mbstring'];
$extensions = [];
foreach ($requiredExtensions as $ext) {
    $extensions[$ext] = extension_loaded($ext);
}

// --- Provera konekcije ka bazi ---------------------------------------
$dbOk      = false;
$dbMessage = '';
$dbVersion = '';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'db',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: ''
    );

    $pdo = new PDO(
        $dsn,
        getenv('DB_USER') ?: '',
        getenv('DB_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]
    );

    $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $dbOk      = true;
} catch (Throwable $e) {
    $dbMessage = $e->getMessage();
}

// --- Provera kljuca ---------------------------------------------------
$secretRaw = (string) (getenv('SECRET_KEY') ?: '');
$secretOk  = $secretRaw !== '' && strlen((string) base64_decode($secretRaw, true)) === 32;

$allOk = $dbOk && $secretOk && !in_array(false, $extensions, true);

/** Mali helper za badge */
function badge(bool $ok): string
{
    return $ok
        ? '<span class="ok">OK</span>'
        : '<span class="bad">GRESKA</span>';
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SyncOPS — status okruzenja</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
            max-width: 48rem;
            margin: 3rem auto;
            padding: 0 1.25rem;
        }
        h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .sub { opacity: 0.65; margin-top: 0; font-size: 0.9rem; }
        table { border-collapse: collapse; width: 100%; margin: 1.5rem 0; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(128,128,128,0.25); }
        th { font-weight: 600; width: 40%; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875em; }
        .ok  { color: #15803d; font-weight: 600; }
        .bad { color: #b91c1c; font-weight: 600; }
        .banner { padding: 0.75rem 1rem; border-radius: 0.5rem; margin: 1.5rem 0; }
        .banner.ok  { background: rgba(21,128,61,0.12); }
        .banner.bad { background: rgba(185,28,28,0.12); }
        .note { opacity: 0.7; font-size: 0.875rem; border-top: 1px solid rgba(128,128,128,0.25); padding-top: 1rem; margin-top: 2rem; }
        pre { white-space: pre-wrap; word-break: break-word; font-size: 0.8125rem; }
    </style>
</head>
<body>

<h1>SyncOPS</h1>
<p class="sub">Status razvojnog okruzenja &middot; <?= date('Y-m-d H:i:s') ?> UTC</p>

<div class="banner <?= $allOk ? 'ok' : 'bad' ?>">
    <?= $allOk
        ? 'Okruzenje je ispravno podignuto. Sledeci korak: migracije baze.'
        : 'Nesto nije podeseno kako treba — vidi stavke oznacene kao GRESKA.' ?>
</div>

<table>
    <tr>
        <th>PHP</th>
        <td><?= badge(PHP_VERSION_ID >= 80300) ?> <code><?= PHP_VERSION ?></code></td>
    </tr>
    <tr>
        <th>Vremenska zona</th>
        <td><?= badge(date_default_timezone_get() === 'UTC') ?> <code><?= date_default_timezone_get() ?></code></td>
    </tr>
    <?php foreach ($extensions as $name => $loaded): ?>
    <tr>
        <th>Ekstenzija <code><?= htmlspecialchars($name) ?></code></th>
        <td><?= badge($loaded) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr>
        <th>Konekcija ka bazi</th>
        <td>
            <?= badge($dbOk) ?>
            <?php if ($dbOk): ?>
                MySQL <code><?= htmlspecialchars($dbVersion) ?></code>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th><code>SECRET_KEY</code></th>
        <td><?= badge($secretOk) ?> <?= $secretOk ? '32 bajta, base64' : 'nedostaje ili nije 32 bajta u base64 zapisu' ?></td>
    </tr>
</table>

<?php if (!$dbOk && $dbMessage !== ''): ?>
<h2 style="font-size:1rem;">Greska pri konekciji</h2>
<pre><?= htmlspecialchars($dbMessage) ?></pre>
<?php endif; ?>

<p class="note">
    Ovo je privremena stranica. Zamenjuje se front controllerom
    (<code>SyncOps\Web\Router</code>) kada <code>src/</code> bude popunjen.
</p>

</body>
</html>
