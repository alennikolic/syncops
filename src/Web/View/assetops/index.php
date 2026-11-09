<?php
// src/Web/View/assetops/index.php
//
// Zatečeno stanje u AssetOPS-u — jedna stranica, dva odeljka: CI-jevi koji
// tamo postoje (_ci.php) i referentni spiskovi iz kojih se bira (_reference.php).
//
// Zasto na istom ekranu: jedno dugme „Prikupi sada" ionako puni oboje u
// istom prolazu, pa su dve stranice bile dve polovine iste slike. Gore stoje
// brojevi koji odlucuju da li sinhronizacija sme da pocne, dole vokabular
// kojim se to opisuje — redom kojim se i gledaju.
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, array<string, mixed>> $panels */
/** @var array<string, list<array<string,mixed>>> $tables */
/** @var array<string, string> $lastSeen */
/** @var string $csrf */

$switchTo = '/assetops';

$total     = array_sum(array_map('count', $tables));
$collected = 0;

foreach ($panels as $panel) {
    $collected += (int) $panel['counts']['total'];
}
?>

<div class="page-head">
    <div>
        <h1>Zatečeno stanje</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            <span class="data"><?= $e($integration->baseUrl()) ?></span> &middot;
            <?= $e($collected) ?> CI &middot; <?= $e($total) ?> referentnih zapisa
        </p>
    </div>
    <div class="head-actions">
        <?php require __DIR__ . '/_switcher.php'; ?>

        <form method="post" action="/assetops/<?= $e($integration->id) ?>/refresh">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn btn-primary">Prikupi sada</button>
        </form>
    </div>
</div>

<?php if ($collected === 0 && $total === 0): ?>

    <div class="panel">
        <div class="empty">
            <p>
                Još ništa nije pročitano iz AssetOPS-a. Klikni „Prikupi sada" — u istom
                prolazu se čitaju referentni spiskovi (lokacije, statusi, visibility grupe,
                oba spiska tipova, definicije atributa) i svi CI-jevi, i virtuelne mašine i
                oprema. Ceo entitet je jedna strana, pa traje sekund.
            </p>
        </div>
    </div>

<?php else: ?>

    <?php require __DIR__ . '/_ci.php'; ?>

    <?php require __DIR__ . '/_reference.php'; ?>

<?php endif; ?>
