<?php
// src/Web/View/integration/index.php
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var string $csrf */

$flavorLabel = [
    'auto'        => 'prepoznaje sam',
    'api_v2'      => '/api',
    'rest_legacy' => '/rest',
];
?>

<div class="page-head">
    <div>
        <h1>Integracije</h1>
        <p>vCenter serveri sa kojih SyncOPS prikuplja podatke.</p>
    </div>
    <a href="/integrations/new" class="btn btn-primary">Dodaj vCenter</a>
</div>

<div class="panel">
    <?php if ($integrations === []): ?>

        <div class="empty">
            <p>Lista je prazna. Dodaj vCenter da bi prikupljanje moglo da počne.</p>
            <a href="/integrations/new" class="btn btn-primary">Dodaj vCenter</a>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Naziv</th>
                    <th>Adresa</th>
                    <th>Servisni nalog</th>
                    <th>API</th>
                    <th>Provera konekcije</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($integrations as $i):
                $rail = !$i->isActive
                    ? 'rail-idle'
                    : ($i->lastTestOk === null ? 'rail-warn' : ($i->lastTestOk ? 'rail-ok' : 'rail-bad'));
            ?>
                <tr>
                    <td class="rail <?= $rail ?>">
                        <a href="/integrations/<?= $e($i->id) ?>/edit"><?= $e($i->name) ?></a>
                        <?php if (!$i->isActive): ?>
                            <div class="muted">isključena</div>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($i->baseUrl()) ?></td>
                    <td class="data"><?= $e($i->username) ?></td>
                    <td>
                        <?= $e($flavorLabel[$i->apiFlavor] ?? $i->apiFlavor) ?>
                        <?php if ($i->apiFlavor === 'auto' && $i->apiFlavorDetected !== null): ?>
                            <div class="data muted"><?= $e($flavorLabel[$i->apiFlavorDetected] ?? $i->apiFlavorDetected) ?><?php
                                if ($i->productVersion !== null) { echo ' ' . $e($i->productVersion); }
                            ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($i->lastTestedAt === null): ?>
                            <span class="muted">nije provereno</span>
                        <?php else: ?>
                            <span class="<?= $i->lastTestOk ? 'state-ok' : 'state-bad' ?>">
                                <?= $i->lastTestOk ? 'uspešno' : 'neuspešno' ?>
                            </span>
                            <div class="data muted"><?= $e($i->lastTestedAt) ?> UTC</div>
                            <?php if (!$i->lastTestOk && $i->lastTestMessage !== null): ?>
                                <div class="muted" style="font-size:13px"><?= $e($i->lastTestMessage) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a href="/integrations/<?= $e($i->id) ?>/edit" class="btn-link">Izmeni</a>
                        <form method="post" action="/integrations/<?= $e($i->id) ?>/state">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <button type="submit" class="btn-link <?= $i->isActive ? 'danger' : '' ?>">
                                <?= $i->isActive ? 'Isključi' : 'Uključi' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
