<?php
// src/Web/View/assetops/index.php
//
// Referentni spiskovi iz AssetOPS-a i mapiranje.
//
// Spiskovi se prikazuju onako kako stoje u AssetOPS-u: lokacije i tipovi
// uvuceni po dubini stabla, statusi sa svojom bojom. Slug stoji uz svako ime
// jer je on identitet — veza pri upisu ide slugom, a slug se ne izvodi iz
// imena ("VM" ima slug "kub").
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, list<array<string,mixed>>> $tables */
/** @var array<string, string> $lastSeen */
/** @var array<string, array<string,mixed>> $mapping */
/** @var array<string, array<string,string>> $options */
/** @var string $csrf */

$titles = [
    'location'           => 'Lokacije',
    'status'             => 'Statusi',
    'visibility_group'   => 'Visibility grupe',
    'virtual_asset_type' => 'Tipovi virtuelnih mašina',
    'asset_type'         => 'Tipovi opreme',
];

/** Red koji nije viđen u poslednjem osvežavanju. */
$stale = static function (array $row, array $lastSeen): bool {
    $max = $lastSeen[(string) $row['kind']] ?? null;

    return $max !== null && (string) $row['last_seen'] < $max;
};

$payload = static function (array $row): array {
    $decoded = json_decode((string) $row['payload'], true);

    return is_array($decoded) ? $decoded : [];
};

$total = array_sum(array_map('count', $tables));
?>

<div class="page-head">
    <div>
        <h1>AssetOPS</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            <span class="data"><?= $e($integration->baseUrl()) ?></span> &middot;
            <?= $e($total) ?> zapisa
        </p>
    </div>
    <div class="head-actions">
        <?php if (count($integrations) > 1): ?>
            <form method="get" action="/assetops">
                <select name="integration" onchange="this.form.submit()">
                    <?php foreach ($integrations as $i): ?>
                        <option value="<?= $e($i->id) ?>" <?= $i->id === $integration->id ? 'selected' : '' ?>>
                            <?= $e($i->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <form method="post" action="/assetops/<?= $e($integration->id) ?>/refresh">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn btn-primary">Prikupi sada</button>
        </form>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="panel">
        <div class="empty">
            <p>
                Još ništa nije prikupljeno. Klikni „Prikupi sada" — pročitaće se lokacije,
                statusi, visibility grupe i oba spiska tipova. Traje sekund.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($titles as $kind => $title):
    $rows = $tables[$kind] ?? [];

    if ($rows === []) {
        continue;
    }
?>
    <h2 class="section-head"><?= $e($title) ?> (<?= $e(count($rows)) ?>)</h2>

    <div class="panel table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Naziv</th>
                    <th>Slug</th>
                    <?php if ($kind === 'location'): ?>
                        <th>Vrsta lokacije</th>
                        <th>Nadređena</th>
                    <?php elseif ($kind === 'status'): ?>
                        <th>Boja</th>
                    <?php elseif ($kind === 'asset_type'): ?>
                        <th>Osnovno sredstvo</th>
                        <th>U rek</th>
                    <?php endif; ?>
                    <th>Viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $p       = $payload($row);
                $missing = $stale($row, $lastSeen);
            ?>
                <tr>
                    <td class="rail <?= $missing ? 'rail-bad' : 'rail-ok' ?>">
                        <?= str_repeat('<span class="tree-indent"></span>', (int) $row['depth']) ?>
                        <?= $e($row['name']) ?>
                    </td>
                    <td class="data"><?= $e($row['slug']) ?></td>

                    <?php if ($kind === 'location'): ?>
                        <td><?= $e($p['location_type']['name'] ?? '—') ?></td>
                        <td class="data"><?= $e($row['parent_slug'] ?? '—') ?></td>
                    <?php elseif ($kind === 'status'): ?>
                        <td>
                            <?php if (($row['color'] ?? '') !== ''): ?>
                                <span class="swatch" style="background: <?= $e($row['color']) ?>"></span>
                                <span class="data"><?= $e($row['color']) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    <?php elseif ($kind === 'asset_type'): ?>
                        <td><?= ($p['is_fixed_asset'] ?? false) ? 'da' : 'ne' ?></td>
                        <td><?= ($p['is_rack_mountable'] ?? false) ? 'da' : 'ne' ?></td>
                    <?php endif; ?>

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
<?php endforeach; ?>

<?php
// -----------------------------------------------------------------
// Mapiranje
// -----------------------------------------------------------------
$fields = [
    'vm' => [
        'naslov'  => 'Virtuelne mašine',
        'opis'    => 'Mašina iz vCentera postaje virtual-asset u AssetOPS-u.',
        'tipovi'  => 'virtual_asset_type',
        'units'   => false,
    ],
    'host' => [
        'naslov'  => 'ESXi hostovi',
        'opis'    => 'Host je fizička oprema, pa postaje asset — sa serijskim brojem i modelom.',
        'tipovi'  => 'asset_type',
        'units'   => true,
    ],
];

$select = static function (string $name, array $choices, ?string $selected) use ($e): string {
    $html = '<select name="' . $e($name) . '"><option value="">— nije izabrano —</option>';

    foreach ($choices as $slug => $label) {
        $html .= '<option value="' . $e($slug) . '"' . ($selected === $slug ? ' selected' : '') . '>'
               . $e($label) . ' (' . $e($slug) . ')</option>';
    }

    return $html . '</select>';
};
?>

<h2 class="section-head">Mapiranje</h2>

<form method="post" action="/assetops/<?= $e($integration->id) ?>/mapping">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <div class="detail-grid">
        <?php foreach ($fields as $kind => $spec):
            $current = $mapping[$kind];
        ?>
            <section class="panel">
                <h2><?= $e($spec['naslov']) ?></h2>
                <p class="hint" style="margin-top:0"><?= $e($spec['opis']) ?></p>

                <div class="field">
                    <label for="<?= $e($kind) ?>_type">Tip</label>
                    <?= $select($kind . '_type', $options[$spec['tipovi']], $current['type_slug']) ?>
                </div>

                <div class="field">
                    <label for="<?= $e($kind) ?>_location">Podrazumevana lokacija</label>
                    <?= $select($kind . '_location', $options['location'], $current['location_slug']) ?>
                </div>

                <div class="field">
                    <label>Visibility grupe</label>
                    <span class="hint">Bez bar jedne grupe AssetOPS odbija kreiranje.</span>
                    <?php if ($options['visibility_group'] === []): ?>
                        <span class="muted">Nije prikupljena nijedna grupa.</span>
                    <?php else: ?>
                        <?php foreach ($options['visibility_group'] as $slug => $label): ?>
                            <label class="check">
                                <input type="checkbox" name="<?= $e($kind) ?>_visibility_groups[]"
                                       value="<?= $e($slug) ?>"
                                       <?= in_array($slug, $current['visibility_groups'], true) ? 'checked' : '' ?>>
                                <?= $e($label) ?> <span class="data muted">(<?= $e($slug) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($spec['units'] === true): ?>
                    <div class="field">
                        <label for="host_units_required">Rek jedinica</label>
                        <span class="hint">
                            Koliko mesta server zauzima u reku. Swagger ga navodi kao obavezno
                            polje pri kreiranju opreme, pa se šalje i kad nije poznato.
                        </span>
                        <input type="number" id="host_units_required" name="host_units_required"
                               min="0" max="100" style="max-width:8rem"
                               value="<?= $e($current['units_required'] ?? 1) ?>">
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Sačuvaj mapiranje</button>
    </div>
</form>

<p class="note-block">
    Veze se u AssetOPS šalju kao <b>slugovi</b>, ne kao imena. Zato uz svako ime stoji i slug —
    i zato se spiskovi prikupljaju umesto da se kucaju: slug se ne izvodi iz imena, pa bi
    pogađanje promašilo (<span class="data">VM</span> ima slug <span class="data">kub</span>).
</p>
