<?php
// src/Web/View/integration/form.php
//
/** @var callable $e */
/** @var \SyncOps\Integration\Integration|null $integration */
/** @var array<string, string> $value */
/** @var array<string, string> $flavors */
/** @var list<string> $errors */
/** @var string $csrf */

$isNew  = $integration === null;
$action = $isNew ? '/integrations' : '/integrations/' . $integration->id;
?>

<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Dodaj vCenter' : $e($integration->name) ?></h1>
        <p>
            <?= $isNew
                ? 'Servisni nalog treba da ima samo Read-only rolu na root objektu, sa propagacijom.'
                : 'Lozinku ostavi praznu ako je ne menjaš.' ?>
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

    <div class="panel">

        <div class="field">
            <label for="name">Naziv</label>
            <span class="hint">Kako ćeš ga prepoznati u listi.</span>
            <input type="text" id="name" name="name" required
                   value="<?= $e($value['name']) ?>" placeholder="vCenter Beograd">
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="hostname">Adresa</label>
                <span class="hint">FQDN ili IP.</span>
                <input type="text" id="hostname" name="hostname" required
                       value="<?= $e($value['hostname']) ?>" placeholder="vcenter.primer.rs">
            </div>

            <div class="field">
                <label for="port">Port</label>
                <span class="hint">&nbsp;</span>
                <input type="number" id="port" name="port" min="1" max="65535"
                       value="<?= $e($value['port']) ?>">
            </div>
        </div>

        <div class="field">
            <label for="username">Korisničko ime</label>
            <input type="text" id="username" name="username" required
                   autocomplete="off"
                   value="<?= $e($value['username']) ?>" placeholder="syncops@vsphere.local">
        </div>

        <div class="field">
            <label for="password">Lozinka</label>
            <?php if (!$isNew): ?>
                <span class="hint">Ostavi prazno da zadržiš postojeću.</span>
            <?php endif; ?>
            <input type="password" id="password" name="password"
                   autocomplete="new-password" <?= $isNew ? 'required' : '' ?>>
        </div>

        <div class="field">
            <label for="api_flavor">Verzija API-ja</label>
            <span class="hint">Ostavi na „prepoznaj sam” osim ako znaš da detekcija ne radi.</span>
            <select id="api_flavor" name="api_flavor">
                <?php foreach ($flavors as $key => $label): ?>
                    <option value="<?= $e($key) ?>" <?= $value['api_flavor'] === $key ? 'selected' : '' ?>>
                        <?= $e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field-inline">
            <input type="checkbox" id="verify_peer" name="verify_peer" value="1"
                   <?= $value['verify_peer'] === '1' ? 'checked' : '' ?>>
            <label for="verify_peer">Proveravaj TLS sertifikat</label>
        </div>
        <span class="hint">Isključeno radi sa self-signed sertifikatom, što je na vCenterima uobičajeno.</span>

    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Dodaj vCenter' : 'Sačuvaj izmene' ?>
        </button>
        <a href="/integrations" class="btn">Odustani</a>
    </div>
</form>
