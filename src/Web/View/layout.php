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
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="data:,">
</head>
<body>

<div class="shell">

    <nav class="sidebar">
        <div class="brand">Sync<span>OPS</span></div>

        <div class="nav">
            <a href="/"             <?= $nav === 'dashboard'    ? 'aria-current="page"' : '' ?>>Pregled</a>
            <a href="/integrations" <?= $nav === 'integrations' ? 'aria-current="page"' : '' ?>>Integracije</a>
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
