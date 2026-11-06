<?php
// src/Web/View/assetops/_ci.php
//
// Zatečeno stanje u AssetOPS-u: koji CI-jevi tamo postoje. Gornja polovina
// stranice Zatečeno stanje; donju nosi _reference.php.
//
// Odeljak ima vrednost i pre ijednog upisa, i to je njegov razlog da postoji.
// Pokazuje tri broja koja odlučuju da li sinhronizacija sme da počne: koliko
// CI-jeva nosi naš ključ spajanja, koliko ih je mapiranog tipa, i gde se te
// dve stvari ne poklapaju.
//
// Neusklade stoje otvorene, spisak CI-jeva skupljen. Nalaz se gleda, inventar
// se pregleda kad nekoga zanima.
//
// Partial: zaglavlje stranice, izbor integracije i dugme „Prikupi sada" su u
// index.php, jer su zajednički za oba odeljka. $collected racuna index.php.
//
/** @var callable $e */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, array<string, mixed>> $panels svaki nosi counts, mismatched, duplicates */
/** @var int $collected */

/** CI koji nije viđen u poslednjem osvežavanju ovog entiteta. */
$staleCi = static function (array $row, ?array $stats): bool {
    $max = $stats['last_seen'] ?? null;

    return $max !== null && (string) $row['last_seen'] < $max;
};
?>

<h2 class="section-head">CI-jevi u AssetOPS-u <span class="muted"><?= $e($collected) ?></span></h2>

<?php if ($collected === 0): ?>
    <div class="panel">
        <p class="note-block">
            Još nijedan CI nije pročitan. Klikni „Prikupi sada" — pročitaće se sve
            virtuelne mašine i sva oprema iz AssetOPS-a, zajedno sa referentnim
            spiskovima. Ceo entitet je jedna strana, pa traje sekund.
        </p>
    </div>
<?php else: ?>

    <?php foreach ($panels as $sourceKind => $panel):
        $counts = $panel['counts'];
        $stats  = $panel['stats'];
    ?>
        <h3 class="section-head"><?= $e($panel['label']) ?></h3>

        <div class="counts">
            <div class="count">
                <b><?= $e(number_format($counts['total'], 0, ',', '.')) ?></b>
                <small>ukupno u AssetOPS-u</small>
            </div>
            <div class="count">
                <b><?= $e(number_format($counts['with_key'], 0, ',', '.')) ?></b>
                <small>nosi naš ključ</small>
            </div>
            <div class="count">
                <b><?= $panel['type_slug'] === null ? '—' : $e(number_format($counts['of_type'], 0, ',', '.')) ?></b>
                <small>mapiranog tipa</small>
            </div>
            <div class="count">
                <?php // Bez izabranog tipa ova dva broja nisu nula nego nepoznato. ?>
                <b class="<?= $counts['mismatched'] > 0 ? 'state-bad' : '' ?>">
                    <?= $panel['type_slug'] === null ? '—' : $e(number_format($counts['mismatched'], 0, ',', '.')) ?>
                </b>
                <small>ključ, a drugi tip</small>
            </div>
        </div>

        <?php if ($panel['type_slug'] === null): ?>
            <p class="note-block state-warn">
                Tip CI-ja za ovu vrstu nije izabran u <a href="/assetops/mapping">Mapiranju</a>.
                Bez njega se ne zna šta bi SyncOPS kreirao — a kod virtuelnih mašina upis bez
                tipa pada sa HTTP 500.
            </p>
        <?php endif; ?>

        <?php if ($panel['correlation'] === null): ?>
            <p class="note-block state-warn">
                Ključ spajanja za ovu vrstu nije izabran u <a href="/assetops/mapping">Mapiranju</a>.
                Dok ga nema, ne može se reći koji CI odgovara kom objektu iz vCentera — spajanje
                radi samo po njemu, jer AssetOPS ne ume da filtrira po vrednosti atributa.
            </p>
        <?php else: ?>
            <p class="muted">
                Ključ spajanja: <span class="data"><?= $e($panel['correlation']) ?></span>
                <?php if ($panel['type_name'] !== null): ?>
                    &middot; mapirani tip: <span class="data"><?= $e(trim((string) $panel['type_name'], '— ')) ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($counts['mismatched'] > 0): ?>
            <div class="panel">
                <p class="note-block state-bad">
                    <?= $e($counts['mismatched']) ?> CI nosi našu vrednost ključa, a nije mapiranog
                    tipa. Zato se i čita ceo entitet: filtrirano čitanje ih ne bi videlo, SyncOPS ih
                    ne bi prepoznao i napravio bi duplikat pored njih. Reši se u AssetOPS-u —
                    vraćanjem tipa, ili promenom mapiranja.
                </p>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>CI</th><th>Tip koji ima</th><th>Vrednost ključa</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($panel['mismatched'] as $row): ?>
                            <tr>
                                <td class="rail rail-bad"><?= $e($row['name']) ?></td>
                                <td class="data"><?= $e($row['type_slug'] ?? '—') ?></td>
                                <td class="data"><?= $e($row['correlation_value']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($panel['duplicates'] !== []): ?>
            <div class="panel">
                <p class="note-block state-bad">
                    Ista vrednost ključa na više CI-jeva. Ključ mora da bude jedinstven, inače
                    nema odgovora na pitanje koji CI odgovara kom objektu. Reši se u AssetOPS-u
                    pre prvog upisa.
                </p>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>Vrednost ključa</th><th>Puta</th><th>CI-jevi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($panel['duplicates'] as $duplicate): ?>
                            <tr>
                                <td class="rail rail-bad data"><?= $e($duplicate['correlation_value']) ?></td>
                                <td><?= $e($duplicate['count']) ?></td>
                                <td><?= $e($duplicate['names']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <details class="ref-list">
                <summary>
                    Svi zapisi
                    <span class="muted"><?= $e($counts['total']) ?></span>
                </summary>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Ime</th>
                                <th>Tip</th>
                                <th>Status</th>
                                <th>Lokacija</th>
                                <?php if ($sourceKind === 'host'): ?>
                                    <th>Serijski broj</th>
                                <?php endif; ?>
                                <th>Vrednost ključa</th>
                                <th>Viđeno</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($panel['rows'] as $row):
                            $missing = $staleCi($row, $stats);
                            $key     = $row['correlation_value'] ?? null;
                        ?>
                            <tr>
                                <td class="rail <?= $missing ? 'rail-bad' : ($key === null ? 'rail-warn' : 'rail-ok') ?>">
                                    <?= $e($row['name']) ?>
                                    <div class="sub data muted"><?= $e($row['uuid']) ?></div>
                                </td>
                                <td class="data"><?= $e($row['type_slug'] ?? '—') ?></td>
                                <td class="data"><?= $e($row['status_slug'] ?? '—') ?></td>
                                <td class="data"><?= $e($row['location_slug'] ?? '—') ?></td>
                                <?php if ($sourceKind === 'host'): ?>
                                    <td class="data"><?= $e($row['serial_number'] ?? '—') ?></td>
                                <?php endif; ?>
                                <td class="data">
                                    <?php if ($key !== null): ?>
                                        <?= $e($key) ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="stacked">
                                    <span class="data"><?= $e(substr((string) $row['last_seen'], 0, 16)) ?></span>
                                    <?php if ($missing): ?>
                                        <span class="sub state-bad">nije u poslednjem osvežavanju</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endforeach; ?>

    <p class="note-block">
        Čita se <b>ceo entitet</b>, ne samo mapirani tip. Filter po tipu radi, ali ne bi
        uštedeo ni jedan poziv — ceo entitet je jedna strana — a ne bi video CI kome je
        neko promenio tip. Takav CI se umesto toga prijavi ovde.
        <br>
        Čita se ruta liste, ne detalji po zapisu: lista nosi i
        <span class="data">attribute_values</span> i <span class="data">description</span>,
        pa je dovoljna i za spajanje i za poređenje. Entitet koji neko polje u listi ne
        vrati ostavlja tu kolonu praznom — zbog jednog polja se ne prelazi na detalje
        po zapisu.
    </p>

<?php endif; ?>
