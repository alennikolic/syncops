<?php
// src/Web/View/integration/form.php
//
// Forma za dodavanje i izmenu integracije.
//
// Sablon ne zna sta je vCenter a sta AssetOPS. Sve razlike — da li postoji
// korisnicko ime, kako se zove tajna, sta pise u objasnjenjima — dolaze iz
// $spec, koji daje IntegrationType. Nov tip integracije se tako dodaje bez
// diranja ovog fajla.
//
/** @var callable $e */
/** @var \SyncOps\Integration\Integration|null $integration */
/** @var string $type */
/** @var array<string, mixed> $spec Opis forme za ovaj tip */
/** @var array<string, string> $value */
/** @var array<string, string> $flavors Dijalekt => opis; polje se vidi samo ako ih ima vise */
/** @var list<string> $errors */
/** @var string $csrf */

$isNew  = $integration === null;
$action = $isNew ? '/integrations' : '/integrations/' . $integration->id;
$noun   = (string) $spec['noun'];
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Dodaj ' . $e($noun) : $e($integration->name) ?></h1>
        <p>
            <?= $isNew
                ? $e($spec['intro'])
                : 'Ostavi ' . $e(mb_strtolower((string) $spec['secret_label'])) . ' prazan ako ga ne menjaš.' ?>
        </p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <ul class="errors">
        <?php foreach ($errors as $error): ?>
            <li><?= $e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="<?= $e($action) ?>" class="form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <?php /* Tip se posle kreiranja ne menja — vCenter ne postaje AssetOPS. */ ?>
    <input type="hidden" name="type" value="<?= $e($type) ?>">

    <div class="panel">

        <div class="field">
            <label for="name">Naziv</label>
            <span class="hint">Kako ćeš ga prepoznati u listi.</span>
            <input type="text" id="name" name="name" required
                   value="<?= $e($value['name']) ?>"
                   placeholder="<?= $e($noun) ?> Beograd">
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="hostname">Adresa</label>
                <span class="hint">FQDN ili IP.</span>
                <input type="text" id="hostname" name="hostname" required
                       value="<?= $e($value['hostname']) ?>"
                       placeholder="<?= $e($spec['host_placeholder']) ?>">
            </div>

            <div class="field">
                <label for="port">Port</label>
                <span class="hint">&nbsp;</span>
                <input type="number" id="port" name="port" min="1" max="65535"
                       value="<?= $e($value['port']) ?>">
            </div>
        </div>

        <?php if ($spec['has_username'] === true): ?>
            <div class="field">
                <label for="username"><?= $e($spec['username_label']) ?></label>
                <input type="text" id="username" name="username" required
                       autocomplete="off"
                       value="<?= $e($value['username']) ?>"
                       placeholder="<?= $e($spec['username_placeholder'] ?? '') ?>">
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="password"><?= $e($spec['secret_label']) ?></label>
            <?php if (!$isNew): ?>
                <span class="hint">Ostavi prazno da zadržiš postojeći.</span>
            <?php elseif (($spec['secret_hint'] ?? '') !== ''): ?>
                <span class="hint"><?= $e($spec['secret_hint']) ?></span>
            <?php endif; ?>
            <input type="password" id="password" name="password"
                   autocomplete="new-password" <?= $isNew ? 'required' : '' ?>>
        </div>

        <?php if (count($flavors) > 1): ?>
            <div class="field">
                <label for="api_flavor">Protokol</label>
                <select id="api_flavor" name="api_flavor">
                    <?php foreach ($flavors as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $value['api_flavor'] === $key ? 'selected' : '' ?>>
                            <?= $e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field field-inline">
            <input type="checkbox" id="verify_peer" name="verify_peer" value="1"
                   <?= $value['verify_peer'] === '1' ? 'checked' : '' ?>>
            <label for="verify_peer">Proveravaj TLS sertifikat</label>
        </div>
        <span class="hint"><?= $e($spec['tls_hint']) ?></span>

    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Dodaj ' . $e($noun) : 'Sačuvaj izmene' ?>
        </button>
        <a href="/integrations" class="btn">Odustani</a>
    </div>
</form>
