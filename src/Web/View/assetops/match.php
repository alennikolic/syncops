<?php
// src/Web/View/assetops/match.php
//
// Spajanje: koji objekat iz vCentera odgovara kom CI-ju u AssetOPS-u.
//
// Ništa se odavde ne upisuje u AssetOPS. Jedino što nastaje su veze u našoj
// bazi — i upravo zato ovaj ekran stoji pre upisa: ono što se ovde propusti
// kasnije postaje duplikat u tuđem sistemu.
//
// Redosled na stranici je redosled pažnje, ne redosled brojeva: prvo ono što
// traži odluku (sukobi, raskinute veze, slabi predlozi), pa tek onda ono što
// je rešeno.
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, array<string, mixed>> $panels */
/** @var string $csrf */

$switchTo = '/assetops/match';

$name = static fn (array $row): string => (string) ($row['name'] ?? '—');
?>

<div class="page-head">
    <div>
        <h1>Spajanje</h1>
        <p>Koji objekat iz vCentera odgovara kom CI-ju. Ništa se ne upisuje u AssetOPS.</p>
    </div>
    <div class="head-actions">
        <?php require __DIR__ . '/_switcher.php'; ?>
    </div>
</div>

<?php foreach ($panels as $sourceKind => $panel):
    $c = $panel['counts'];
?>
    <h2 class="section-head"><?= $e($panel['label']) ?></h2>

    <div class="counts">
        <div class="count">
            <b><?= $e($c['inventory']) ?></b>
            <small>u vCenteru</small>
        </div>
        <div class="count">
            <b><?= $e($c['linked']) ?></b>
            <small>već povezano</small>
        </div>
        <div class="count">
            <b class="<?= $c['strong'] > 0 ? 'state-ok' : '' ?>"><?= $e($c['strong']) ?></b>
            <small>po ključu</small>
        </div>
        <div class="count">
            <b class="<?= $c['weak'] > 0 ? 'state-warn' : '' ?>"><?= $e($c['weak']) ?></b>
            <small>slabi predlozi</small>
        </div>
        <div class="count">
            <b><?= $e($c['unmatched']) ?></b>
            <small>bilo bi kreirano</small>
        </div>
        <div class="count">
            <b class="<?= ($c['conflicts'] + $c['broken']) > 0 ? 'state-bad' : '' ?>">
                <?= $e($c['conflicts'] + $c['broken']) ?>
            </b>
            <small>traži odluku</small>
        </div>
    </div>

    <?php if ($panel['correlation'] === null): ?>
        <p class="note-block state-warn">
            Ključ spajanja za ovu vrstu nije podešen u <a href="/assetops/mapping">Mapiranju</a>,
            ili nema izvorno polje iz vCentera. Dok ga nema, spajanje može samo po imenu — a to
            je predlog, ne veza.
        </p>
    <?php else: ?>
        <p class="muted">
            Ključ spajanja se puni iz <span class="data"><?= $e($panel['correlation']) ?></span>.
        </p>
    <?php endif; ?>

    <?php // --- sukobi: ne mogu da se reše klikom ---------------------- ?>
    <?php if ($panel['conflicts'] !== []): ?>
        <div class="panel">
            <p class="note-block state-bad">
                Dvosmisleno spajanje. Jedan objekat odgovara na više CI-jeva, ili više
                objekata na isti CI. Ovo se ne nudi za potvrdu jer se potvrđuje na brzinu,
                a ovde treba pogledati — reši se u AssetOPS-u, pa ponovo prikupi.
            </p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Objekat</th><th>Kandidati</th><th>Zašto</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($panel['conflicts'] as $conflict): ?>
                        <tr>
                            <td class="rail rail-bad"><?= $e($name($conflict['source'])) ?></td>
                            <td>
                                <?php foreach ($conflict['cis'] as $ci): ?>
                                    <div class="data">
                                        <?= $e($ci['name']) ?>
                                        <span class="muted"><?= $e($ci['type_slug'] ?? '—') ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (isset($conflict['other'])): ?>
                                    <div class="sub muted">
                                        drugi objekat: <?= $e($name($conflict['other'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= $e($conflict['reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php // --- veze čiji je CI nestao ------------------------------- ?>
    <?php if ($panel['broken'] !== []): ?>
        <div class="panel">
            <p class="note-block state-bad">
                Veza pokazuje na CI kojeg više nema u AssetOPS-u. Veza se ne briše sama —
                CI je možda obrisan greškom. Dok stoji, SyncOPS taj objekat neće ni kreirati
                ni ažurirati.
            </p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Objekat</th><th>Nestali CI</th><th>Spojeno</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($panel['broken'] as $brokenLink): ?>
                        <tr>
                            <td class="rail rail-bad"><?= $e($name($brokenLink['source'])) ?></td>
                            <td class="data"><?= $e($brokenLink['link']['ci_uuid']) ?></td>
                            <td class="data"><?= $e(substr((string) $brokenLink['link']['linked_at'], 0, 16)) ?></td>
                            <td>
                                <form method="post" action="/assetops/<?= $e($integration->id) ?>/unlink">
                                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="source_kind" value="<?= $e($sourceKind) ?>">
                                    <input type="hidden" name="source_id" value="<?= $e($brokenLink['source']['id']) ?>">
                                    <button type="submit" class="btn btn-danger">Raskini</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php // --- ono što se pravi jednim dugmetom ---------------------- ?>
    <?php if ($panel['strong'] !== [] || $panel['weak'] !== []): ?>
        <form method="post" action="/assetops/<?= $e($integration->id) ?>/match">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="source_kind" value="<?= $e($sourceKind) ?>">

            <div class="panel">
                <?php if ($panel['strong'] !== []): ?>
                    <p class="note-block">
                        <b><?= $e(count($panel['strong'])) ?></b> veza po ključu spajanja. Vrednost
                        ključa je jednoznačna, pa se ove prave bez potvrde — dovoljno je
                        kliknuti „Poveži".
                    </p>

                    <details class="ref-list">
                        <summary>Po ključu <span class="muted"><?= $e(count($panel['strong'])) ?></span></summary>

                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr><th>Objekat</th><th>CI</th><th>Vrednost ključa</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($panel['strong'] as $strong): ?>
                                    <tr>
                                        <td class="rail rail-ok"><?= $e($name($strong['source'])) ?></td>
                                        <td><?= $e($strong['ci']['name']) ?></td>
                                        <td class="data"><?= $e($strong['ci']['correlation_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($panel['weak'] !== []): ?>
                    <p class="note-block state-warn">
                        <b><?= $e(count($panel['weak'])) ?></b> predloga po imenu ili serijskom broju.
                        Ime nije jedinstveno i menja se, pa ovo nije veza dok je ne potvrdiš.
                        Pogledaj i tip CI-ja — predlog ne gleda da li je tip onaj iz Mapiranja.
                    </p>

                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Potvrdi</th><th>Objekat</th><th>CI</th>
                                    <th>Tip CI-ja</th><th>Po čemu</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($panel['weak'] as $weak):
                                $ref = $weak['source']['id'] . ':' . $weak['ci']['uuid'];
                            ?>
                                <tr>
                                    <td class="rail rail-warn">
                                        <input type="checkbox" name="weak[]" value="<?= $e($ref) ?>">
                                    </td>
                                    <td><?= $e($name($weak['source'])) ?></td>
                                    <td><?= $e($weak['ci']['name']) ?></td>
                                    <td class="data"><?= $e($weak['ci']['type_slug'] ?? '—') ?></td>
                                    <td class="stacked">
                                        <span><?= $weak['matched_by'] === 'name' ? 'isto ime' : 'isti serijski broj' ?></span>
                                        <span class="sub data"><?= $e($weak['on']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        Poveži
                        <?php if ($panel['strong'] !== [] && $panel['weak'] !== []): ?>
                            (<?= $e(count($panel['strong'])) ?> po ključu + izabrane)
                        <?php elseif ($panel['strong'] !== []): ?>
                            (<?= $e(count($panel['strong'])) ?> po ključu)
                        <?php else: ?>
                            (izabrane)
                        <?php endif; ?>
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php // --- CI-jevi sa našim ključem koje niko nije uzeo ---------- ?>
    <?php if ($panel['orphans'] !== []): ?>
        <div class="panel">
            <p class="note-block state-warn">
                <?= $e(count($panel['orphans'])) ?> CI nosi našu vrednost ključa, a nijedan objekat
                iz vCentera mu ne odgovara. To su ili ostaci od obrisanih mašina — tada ih
                rešava faza nestanka — ili znak da spajanje nije našlo ono što je trebalo.
            </p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>CI</th><th>Tip</th><th>Vrednost ključa</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($panel['orphans'] as $orphan): ?>
                        <tr>
                            <td class="rail rail-warn"><?= $e($orphan['ci']['name']) ?></td>
                            <td class="data"><?= $e($orphan['ci']['type_slug'] ?? '—') ?></td>
                            <td class="data"><?= $e($orphan['ci']['correlation_value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php // --- rešeno ----------------------------------------------- ?>
    <?php if ($panel['linked'] !== []): ?>
        <div class="panel">
            <details class="ref-list">
                <summary>
                    Povezano
                    <span class="muted"><?= $e(count($panel['linked'])) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>Objekat</th><th>CI</th><th>Spojeno po</th><th>Poslednji upis</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($panel['linked'] as $row): ?>
                            <tr>
                                <td class="rail <?= $row['drift'] ? 'rail-warn' : 'rail-ok' ?>">
                                    <?= $e($name($row['source'])) ?>
                                    <?php if ($row['drift']): ?>
                                        <div class="sub state-warn">
                                            ključ se razišao — CI nosi drugu vrednost
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $e($row['ci']['name']) ?></td>
                                <td class="data"><?= $e($row['link']['matched_by']) ?></td>
                                <td class="data">
                                    <?= $row['link']['pushed_at'] === null
                                        ? '<span class="muted">nije upisivano</span>'
                                        : $e(substr((string) $row['link']['pushed_at'], 0, 16)) ?>
                                </td>
                                <td>
                                    <form method="post" action="/assetops/<?= $e($integration->id) ?>/unlink">
                                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                        <input type="hidden" name="source_kind" value="<?= $e($sourceKind) ?>">
                                        <input type="hidden" name="source_id" value="<?= $e($row['source']['id']) ?>">
                                        <button type="submit" class="btn">Raskini</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <?php if ($c['unmatched'] > 0): ?>
        <div class="panel">
            <details class="ref-list">
                <summary>
                    Nema para u AssetOPS-u
                    <span class="muted"><?= $e($c['unmatched']) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Objekat</th></tr></thead>
                        <tbody>
                        <?php foreach ($panel['unmatched'] as $row): ?>
                            <tr><td class="rail"><?= $e($name($row['source'])) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<p class="note-block">
    Slabo spajanje postoji samo zbog prvog prolaza: dok SyncOPS nije ništa upisao, nijedan
    CI ne nosi ključ, pa bi bez njega svaka postojeća mašina izgledala kao nova i dobila
    duplikat. Posle prvog upisa ovi predlozi nestaju sami.
</p>
