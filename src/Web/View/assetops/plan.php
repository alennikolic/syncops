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
/** @var list<array<string, mixed>> $journal */
/** @var bool $applied da li je ikada nešto upisano */
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

    <?php if ($panel['actions'] !== []): ?>
        <?php
        // Jedino dugme u aplikaciji koje menja nešto u AssetOPS-u. Plan se
        // pri kliku računa iznova — ovaj obrazac ne nosi šta se upisuje,
        // samo za koju vrstu izvora.
        $total = count($panel['actions']);
        $limit = $applied ? min($total, 25) : min($total, 5);
        ?>
        <form method="post" action="/assetops/<?= $e($integration->id) ?>/apply">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="source_kind" value="<?= $e($sourceKind) ?>">

            <div class="panel">
                <?php if (!$applied): ?>
                    <p class="note-block state-warn">
                        Ovo bi bio <b>prvi upis u AssetOPS</b>. Prolazi samo prvih
                        <?= $e($limit) ?> zapisa, pa ih pogledaj tamo pre ostatka — posle
                        toga ograničenje više ne važi. Ništa se ne briše: SyncOPS samo
                        kreira i menja.
                    </p>
                <?php elseif ($total > $limit): ?>
                    <p class="note-block">
                        Plan ima <?= $e($total) ?> akcija; u jednom prolazu ide
                        <?= $e($limit) ?>. Pokreni više puta dok ne ostane ništa.
                    </p>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        Pokreni sinhronizaciju (<?= $e($limit) ?><?= $total > $limit ? ' od ' . $e($total) : '' ?>)
                    </button>
                </div>
            </div>
        </form>
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

<?php if ($journal !== []): ?>
    <h2 class="section-head">Dnevnik upisa</h2>

    <div class="panel">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Kada</th><th>Objekat</th><th>Šta</th><th>Ishod</th><th>Poruka</th></tr>
                </thead>
                <tbody>
                <?php foreach ($journal as $entry):
                    $rail = match ($entry['status']) {
                        'applied' => 'rail-ok',
                        'skipped' => 'rail-idle',
                        default   => 'rail-bad',
                    };
                ?>
                    <tr>
                        <td class="rail <?= $rail ?> data">
                            <?= $e(substr((string) $entry['created_at'], 0, 16)) ?>
                        </td>
                        <td>
                            <?= $e($entry['source_name']) ?>
                            <?php if ($entry['ci_uuid'] !== null): ?>
                                <div class="sub data muted"><?= $e($entry['ci_uuid']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $entry['action'] === 'create' ? 'kreiranje' : 'izmena' ?></td>
                        <td class="<?= $entry['status'] === 'failed' ? 'state-bad' : '' ?>">
                            <?= match ($entry['status']) {
                                'applied' => 'upisano',
                                'skipped' => 'preskočeno',
                                default   => 'neuspelo',
                            } ?>
                            <?php if ($entry['http_status'] !== null): ?>
                                <span class="sub data muted">HTTP <?= $e($entry['http_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $e($entry['message'] ?? '—') ?>

                            <?php if ($entry['status'] === 'failed' && ($entry['payload'] ?? null) !== null): ?>
                                <?php // Kod neuspeha poruka servera cesto ne kaze dovoljno.
                                      // Poslato telo je jedino mesto na kom se vidi sta je
                                      // tacno otislo — i obicno odmah pokaze sta fali. ?>
                                <details class="ref-list">
                                    <summary>Poslato telo</summary>
                                    <pre class="raw-full"><?= $e(json_encode(
                                        json_decode((string) $entry['payload'], true),
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    )) ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<p class="note-block">
    Pred svaki upis CI se čita <b>iznova</b>, i telo se sastavlja od onoga što je tada u
    AssetOPS-u. Između plana i upisa zapis je mogao da se promeni — a izmena koja više nije
    potrebna tako nestane sama, bez upisa.
</p>

<p class="note-block">
    Telo izmene se ne sastavlja od polja koja se menjaju nego od pročitanog CI-ja, pa se
    preko njega primenjuju izmene. Razlog je izmeren: <span class="data">PUT</span> briše
    svaki EAV atribut koji nije poslat, a među njima bi bio i ključ spajanja — veza sa tim
    CI-jem bi nestala, i sledeći prolaz bi napravio duplikat.
</p>
