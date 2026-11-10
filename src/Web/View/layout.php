<?php
// src/Web/View/layout.php
//
// Okvir svake prijavljene stranice: bocna navigacija + sadrzaj.
//
/** @var callable $e */
/** @var string $content */
/** @var string $title */
/** @var string|null $nav */
/** @var array{type: string, message: string}|null $flash */
/** @var string $csrfGlobal */

$nav = $nav ?? '';
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · SyncOPS</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $e($assetVersion ?? '1') ?>">
    <link rel="icon" href="data:,">
</head>
<body>

<div class="shell">

    <nav class="sidebar">
        <div class="brand">Sync<span>OPS</span></div>

        <div class="nav">
            <a href="/"         <?= $nav === 'dashboard' ? 'aria-current="page"' : '' ?>>Pregled</a>

            <div class="nav-group">Inventar</div>
            <a href="/vms"      <?= $nav === 'vms'      ? 'aria-current="page"' : '' ?>>Virtuelne mašine</a>
            <a href="/hosts"    <?= $nav === 'hosts'    ? 'aria-current="page"' : '' ?>>Hostovi</a>
            <a href="/clusters" <?= $nav === 'clusters' ? 'aria-current="page"' : '' ?>>Klasteri</a>
            <a href="/datastores" <?= $nav === 'datastores' ? 'aria-current="page"' : '' ?>>Datastore</a>
            <a href="/licenses" <?= $nav === 'licenses' ? 'aria-current="page"' : '' ?>>Licence</a>

            <div class="nav-group">AssetOPS</div>
            <a href="/assetops"         <?= $nav === 'assetops'         ? 'aria-current="page"' : '' ?>>Zatečeno stanje</a>
            <a href="/assetops/mapping" <?= $nav === 'assetops-mapping' ? 'aria-current="page"' : '' ?>>Mapiranje</a>
            <a href="/assetops/match"   <?= $nav === 'assetops-match'   ? 'aria-current="page"' : '' ?>>Spajanje</a>
            <a href="/assetops/plan"    <?= $nav === 'assetops-plan'    ? 'aria-current="page"' : '' ?>>Plan</a>
            <a href="/assetops/journal" <?= $nav === 'assetops-journal' ? 'aria-current="page"' : '' ?>>Dnevnik upisa</a>

            <div class="nav-group">Podešavanje</div>
            <a href="/runs"         <?= $nav === 'runs'         ? 'aria-current="page"' : '' ?>>Prikupljanja</a>
            <a href="/integrations" <?= $nav === 'integrations' ? 'aria-current="page"' : '' ?>>Integracije</a>
            <a href="/db"           <?= $nav === 'database'     ? 'aria-current="page"' : '' ?>>Baza</a>
        </div>

        <div class="sidebar-foot">
            <div>Prijavljen</div>
            <form method="post" action="/logout">
                <input type="hidden" name="csrf" value="<?= $e($csrfGlobal) ?>">
                <button type="submit">Odjavi se</button>
            </form>
        </div>
    </nav>

    <main class="main">
        <?php if (!empty($flash)): ?>
            <div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : '' ?>">
                <?= $e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </main>

</div>

</body>
</html>
