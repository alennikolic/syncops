<?php
// src/Web/View/assetops/_switcher.php
//
// Izbor AssetOPS integracije, kada ih ima vise od jedne. Ukljucuju ga i
// Referentni podaci i Mapiranje, da izbor stoji na istom mestu na obe
// stranice.
//
// $switchTo je putanja na koju se vraca posle izbora — svaka stranica ostaje
// na sebi.
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var string $switchTo */

if (count($integrations) < 2) {
    return;
}
?>
<form method="get" action="<?= $e($switchTo) ?>">
    <select name="integration" onchange="this.form.submit()">
        <?php foreach ($integrations as $i): ?>
            <option value="<?= $e($i->id) ?>" <?= $i->id === $integration->id ? 'selected' : '' ?>>
                <?= $e($i->name) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
