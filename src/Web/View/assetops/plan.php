<?php
// src/Web/View/assetops/plan.php
//
// Šta bi se upisalo u AssetOPS. Ništa se odavde ne upisuje.
//
// Ovo je poslednji ekran pre nego što SyncOPS prvi put nešto promeni u tuđem
// sistemu, i jedino mesto na kom se greška u mapiranju vidi pre nego što
// postane 156 pogrešnih zapisa. Zato izmene stoje otvorene, po polju, sa
// starom i novom vrednošću — a ne kao broj „12 izmena".
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, array<string, mixed>> $panels */
/** @var string $csrf */

$switchTo = '/assetops/plan';

/** Vrednost u nešto što se može pročitati u tabeli. */
$show = static function (mixed $value) use ($e): string {
    if ($value === null || $value === '') {
        return '<span class="muted">prazno</span>';
    }

    if (is_bool($value)) {
        return $value ? 'da' : 'ne';
    }

    if (is_array($value)) {
        return $e(implode(', ', array_map(strval(...), $value)));
    }

    return $e((string) $value);
};
?>

<div class="page-head">
    <div>
        <h1>Plan</h1>
        <p>Šta bi se upisalo u AssetOPS. Ništa se odavde ne upisuje.</p>
    </div>
    <div class="head-actions">
        <?php require __DIR__ . '/_switcher.php'; ?>
    </div>
</div>

<?php foreach ($panels as $sourceKind => $panel):
    $c      = $panel['counts'];
    $labels = $panel['labels'];

    $label = static fn (string $ref): string => $labels[$ref] ?? $ref;

    $creates = array_values(array_filter($panel['actions'], static fn (array $a): bool => $a['action'] === 'create'));
    $updates = array_values(array_filter($panel['actions'], static fn (array $a): bool => $a['action'] === 'update'));
?>
    <h2 class="section-head"><?= $e($panel['label']) ?></h2>

    <div class="counts">
        <div class="count">
            <b><?= $e($c['inventory']) ?></b>
            <small>u vCenteru</small>
        </div>
        <div class="count">
            <b class="<?= $c['create'] > 0 ? 'state-ok' : '' ?>"><?= $e($c['create']) ?></b>
            <small>kreiralo bi se</small>
        </div>
        <div class="count">
            <b><?= $e($c['update']) ?></b>
            <small>menjalo bi se</small>
        </div>
        <div class="count">
            <b><?= $e($c['inventory'] - $c['create'] - $c['update'] - $c['skipped']) ?></b>
            <small>bez promene</small>
        </div>
        <div class="count">
            <b class="<?= $c['edited'] > 0 ? 'state-warn' : '' ?>"><?= $e($c['edited']) ?></b>
            <small>ručno menjano</small>
        </div>
        <div class="count">
            <b><?= $e($c['skipped']) ?></b>
            <small>preskočeno</small>
        </div>
    </div>

    <?php if ($c['edited'] > 0): ?>
        <p class="note-block state-warn">
            Kod <?= $e($c['edited']) ?> objekta zatečena vrednost se razlikuje i od vCentera i od
            onoga što je SyncOPS poslednji put poslao — neko je polje menjao ručno. Pravilo upisa
            i dalje odlučuje; ovo stoji da se vidi šta bi se pregazilo.
        </p>
    <?php endif; ?>

    <?php if ($updates !== []): ?>
        <div class="panel">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Objekat</th><th>Polje</th><th>Sada u AssetOPS-u</th><th>Postalo bi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($updates as $action):
                        $editedRefs = array_column($action['edited'], 'ref');
                        $first      = true;
                    ?>
                        <?php foreach ($action['changes'] as $change):
                            $byHand = in_array($change['ref'], $editedRefs, true);
                        ?>
                            <tr>
                                <td class="rail <?= $byHand ? 'rail-warn' : 'rail-ok' ?>">
                                    <?php if ($first): ?>
                                        <?= $e($action['source']['name'] ?? '—') ?>
                                        <div class="sub muted">→ <?= $e($action['ci']['name'] ?? '') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $e($label($change['ref'])) ?>
                                    <?php if ($byHand): ?>
                                        <div class="sub state-warn">menjano ručno</div>
                                    <?php endif; ?>
                                </td>
                                <td class="data"><?= $show($change['from']) ?></td>
                                <td class="data"><?= $show($change['to']) ?></td>
                            </tr>
                        <?php $first = false; endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($creates !== []): ?>
        <div class="panel">
            <details class="ref-list">
                <summary>
                    Kreiralo bi se
                    <span class="muted"><?= $e(count($creates)) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>Objekat</th><th>Šta bi se poslalo</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($creates as $action): ?>
                            <tr>
                                <td class="rail rail-ok"><?= $e($action['source']['name'] ?? '—') ?></td>
                                <td>
                                    <?php foreach ($action['changes'] as $change): ?>
                                        <div>
                                            <?= $e($label($change['ref'])) ?>:
                                            <span class="data"><?= $show($change['to']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <?php if ($panel['skipped'] !== []): ?>
        <div class="panel">
            <details class="ref-list">
                <summary>
                    Preskočeno
                    <span class="muted"><?= $e(count($panel['skipped'])) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Objekat</th><th>Zašto</th></tr></thead>
                        <tbody>
                        <?php foreach ($panel['skipped'] as $skip): ?>
                            <tr>
                                <td class="rail"><?= $e($skip['source']['name'] ?? '—') ?></td>
                                <td><?= $e($skip['reason']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <?php
    // Polja koja su otpala pri razrešavanju: mapirana su, ali vrednost nije
    // mogla da se upotrebi. Objekat i dalje prolazi — fali mu samo to polje.
    $unresolved = [];

    foreach ($panel['actions'] as $action) {
        foreach ($action['unresolved'] as $one) {
            $unresolved[$one['ref'] . ' — ' . $one['reason']] ??= 0;
            $unresolved[$one['ref'] . ' — ' . $one['reason']]++;
        }
    }
    ?>
    <?php if ($unresolved !== []): ?>
        <div class="panel">
            <details class="ref-list">
                <summary>
                    Polja koja nisu popunjena
                    <span class="muted"><?= $e(count($unresolved)) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Polje i razlog</th><th>Na koliko objekata</th></tr></thead>
                        <tbody>
                        <?php foreach ($unresolved as $what => $count): ?>
                            <tr>
                                <td class="rail rail-warn"><?= $e($what) ?></td>
                                <td><?= $e($count) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<p class="note-block">
    Telo izmene se ne sastavlja od polja koja se menjaju nego od pročitanog CI-ja, pa se
    preko njega primenjuju izmene. Razlog je izmeren: <span class="data">PUT</span> briše
    svaki EAV atribut koji nije poslat, a među njima bi bio i ključ spajanja — veza sa tim
    CI-jem bi nestala, i sledeći prolaz bi napravio duplikat.
</p>
