<?php
// src/Web/View/assetops/index.php
//
// Referentni spiskovi prikupljeni iz AssetOPS-a.
//
// Spiskovi stoje skupljeni: lokacija ume da bude na desetine i inace bi
// pojele ceo ekran. Otvara ih onaj ko bas hoce da ih pregleda, a u sazetku
// stoji koliko ih ima.
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, list<array<string,mixed>>> $tables */
/** @var array<string, string> $lastSeen */
/** @var string $csrf */

$titles = [
    'location'           => 'Lokacije',
    'status'             => 'Statusi',
    'visibility_group'   => 'Visibility grupe',
    'virtual_asset_type' => 'Tipovi virtuelnih mašina',
    'asset_type'         => 'Tipovi opreme',
    'eav_attribute'      => 'Definicije atributa',
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

$total      = array_sum(array_map('count', $tables));
$switchTo   = '/assetops';
?>

<div class="page-head">
    <div>
        <h1>Referentni podaci</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            <span class="data"><?= $e($integration->baseUrl()) ?></span> &middot;
            <?= $e($total) ?> zapisa
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

<?php if ($total === 0): ?>
    <div class="panel">
        <div class="empty">
            <p>
                Još ništa nije prikupljeno. Klikni „Prikupi sada" — pročitaće se lokacije,
                statusi, visibility grupe, oba spiska tipova i definicije atributa. Traje sekund.
            </p>
        </div>
    </div>
<?php else: ?>

    <div class="panel">
        <?php foreach ($titles as $kind => $title):
            $rows = $tables[$kind] ?? [];

            if ($rows === []) {
                continue;
            }
        ?>
            <details class="ref-list">
                <summary>
                    <?= $e($title) ?>
                    <span class="muted"><?= $e(count($rows)) ?></span>
                </summary>

                <div class="table-scroll">
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
                                <?php elseif ($kind === 'eav_attribute'): ?>
                                    <th>Tip</th>
                                    <th>Entiteti</th>
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
                                <?php elseif ($kind === 'eav_attribute'): ?>
                                    <td class="data"><?= $e($p['attribute_type']['slug'] ?? $p['attribute_type']['name'] ?? '—') ?></td>
                                    <td class="data">
                                        <?php
                                        $entities = [];

                                        foreach ((array) ($p['attribute_entities'] ?? []) as $entity) {
                                            $entities[] = is_array($entity)
                                                ? (string) ($entity['slug'] ?? $entity['name'] ?? '')
                                                : (string) $entity;
                                        }

                                        echo $e(implode(', ', array_filter($entities)) ?: '—');
                                        ?>
                                    </td>
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
            </details>
        <?php endforeach; ?>
    </div>

    <p class="note-block">
        Ovi spiskovi su ono iz čega se bira u <a href="/assetops/mapping">Mapiranju</a>.
        Identitet je <b>slug</b>, ne ime — veze se u AssetOPS šalju slugom, a slug se ne
        izvodi iz imena: <span class="data">VM</span> ima slug <span class="data">kub</span>,
        <span class="data">Beograd</span> ima <span class="data">beograd-3</span>.
    </p>

<?php endif; ?>
