<?php
// src/Web/View/integration/index.php
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var string $csrf */

// Kratke oznake dijalekata dolaze iz IntegrationType, po tipu integracije —
// ovde se vise ne drzi sopstveni spisak koji ume da zaostane za pravim.
// Nepoznat dijalekt se vraca onakav kakav stoji u bazi, pa stariji red ne
// obori ekran.
$flavor = static fn (\SyncOps\Integration\Integration $i, ?string $f): string
    => \SyncOps\Integration\IntegrationType::flavorLabel($i->type, $f);
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
                        <?= $e($flavor($i, $i->apiFlavor)) ?>
                        <?php if ($i->apiFlavor === 'auto' && $i->apiFlavorDetected !== null): ?>
                            <div class="data muted"><?= $e($flavor($i, $i->apiFlavorDetected)) ?><?php
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
                        <?php if ($i->isActive): ?>
                            <form method="post" action="/integrations/<?= $e($i->id) ?>/discover">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <button type="submit" class="btn-link">Prikupi</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/integrations/<?= $e($i->id) ?>/test">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <button type="submit" class="btn-link">Proveri vezu</button>
                        </form>
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
