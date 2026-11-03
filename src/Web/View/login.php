<?php
// src/Web/View/login.php
//
/** @var callable $e */
/** @var bool $configured */
/** @var string $csrf */
/** @var array{type: string, message: string}|null $flash */
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prijava · SyncOPS</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $e($assetVersion ?? '1') ?>">
    <link rel="icon" href="data:,">
</head>
<body>

<div class="login-shell">
    <div class="login">
        <div class="brand">Sync<span>OPS</span></div>

        <?php if (!empty($flash)): ?>
            <div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : '' ?>">
                <?= $e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$configured): ?>

            <div class="panel">
                <p style="margin-top:0">Lozinka za pristup još nije podešena, pa je GUI zatvoren.</p>
                <p class="muted" style="font-size:14px">Generiši hash i upiši ga u <code class="data">.env</code> kao <code class="data">APP_PASSWORD_HASH</code>, pa restartuj kontejner:</p>
                <pre class="data" style="margin:0;white-space:pre-wrap">docker compose exec app php bin/syncops password:hash
docker compose restart app</pre>
            </div>

        <?php else: ?>

            <form method="post" action="/login" class="panel">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

                <div class="field">
                    <label for="password">Lozinka</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" autofocus required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Prijavi se</button>
                </div>
            </form>

        <?php endif; ?>
    </div>
</div>

</body>
</html>
