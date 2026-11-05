<?php
// src/Web/View/assetops/index.php
//
// Referentni spiskovi iz AssetOPS-a i mapiranje vCenter objekata na njih.
//
// Stranica ima dva dela. Gore su prikupljeni spiskovi, skupljeni — otvara ih
// onaj ko bas hoce da ih pregleda; lokacija ume da bude na desetine i inace
// bi pojela ceo ekran. Dole je mapiranje, jedna tabela po vrsti izvora.
//
// U tabeli su tri vrste ciljeva i razlikuju se po tome odakle im vrednost:
//   veza      iz prikupljenog spiska (status, lokacija, grupe) — salje se slug
//   nativno   polje CI-ja, puni se iz vCentera
//   atribut   EAV, puni se iz vCentera, oblik zavisi od tipa atributa
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, list<array<string,mixed>>> $tables */
/** @var array<string, string> $lastSeen */
/** @var array<string, array<string,mixed>> $mapping */
/** @var array<string, array<string,string>> $types */
/** @var array<string, list<array<string,mixed>>> $fields */
/** @var array<string, array<string, array<string,string>>> $sourceGroups */
/** @var array<string, string> $policies */
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

$total = array_sum(array_map('count', $tables));
?>

<div class="page-head">
    <div>
        <h1>AssetOPS</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            <span class="data"><?= $e($integration->baseUrl()) ?></span> &middot;
            <?= $e($total) ?> referentnih zapisa
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
                statusi, visibility grupe, oba spiska tipova i definicije atributa. Traje sekund.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($total > 0): ?>
    <h2 class="section-head">Prikupljeno iz AssetOPS-a</h2>

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
<?php endif; ?>

<?php
// -----------------------------------------------------------------
// Mapiranje
// -----------------------------------------------------------------
$blocks = [
    'vm' => [
        'naslov' => 'Virtuelne mašine',
        'opis'   => 'Mašina iz vCentera postaje virtual-asset. Ključ spajanja je po pravilu '
                  . 'instance_uuid — ne menja se pri preimenovanju, vMotion-u ni reinstalaciji gosta.',
        'tipovi' => 'Tip virtuelne mašine',
    ],
    'host' => [
        'naslov' => 'ESXi hostovi',
        'opis'   => 'Host je fizička oprema, pa postaje asset. Ključ spajanja je hardware_uuid — '
                  . 'ne zavisi od toga kako proizvođač prijavljuje serijski broj.',
        'tipovi' => 'Tip opreme',
    ],
];

/** Padajući spisak izvornih polja iz vCentera, grupisan. */
$sourceSelect = static function (string $kind, array $row, array $groups) use ($e): string {
    $html = '<select name="map[' . $e($kind) . '][' . $e($row['ref']) . '][source]">'
          . '<option value="">— ne puni se —</option>';

    foreach ($groups as $group => $columns) {
        $html .= '<optgroup label="' . $e($group) . '">';

        foreach ($columns as $column => $label) {
            $html .= '<option value="' . $e($column) . '"'
                   . ($row['source_field'] === $column ? ' selected' : '') . '>'
                   . $e($label) . '</option>';
        }

        $html .= '</optgroup>';
    }

    return $html . '</select>';
};

/** Rucno upisana vrednost, za polja kojima u vCenteru nema parnjaka. */
$constantInput = static function (string $kind, array $row) use ($e): string {
    $value = $row['fixed'][0] ?? '';

    return '<input type="' . ($row['type'] === 'int' ? 'number' : 'text') . '"'
         . ' name="map[' . $e($kind) . '][' . $e($row['ref']) . '][fixed]"'
         . ' value="' . $e($value) . '" style="max-width:9rem"'
         . ($row['type'] === 'int' ? ' min="0" max="1000"' : '') . '>';
};

/** Fiksna vrednost veze, izabrana iz prikupljenog spiska. */
$fixedSelect = static function (string $kind, array $row) use ($e): string {
    if ($row['options'] === []) {
        return '<span class="muted">spisak nije prikupljen</span>';
    }

    $name = 'map[' . $e($kind) . '][' . $e($row['ref']) . '][fixed]';

    if ($row['multi'] === true) {
        $html = '';

        foreach ($row['options'] as $slug => $label) {
            $html .= '<label class="check"><input type="checkbox" name="' . $name . '[]"'
                   . ' value="' . $e($slug) . '"'
                   . (in_array((string) $slug, $row['fixed'], true) ? ' checked' : '') . '>'
                   . $e($label) . ' <span class="data muted">(' . $e($slug) . ')</span></label>';
        }

        return $html;
    }

    $html = '<select name="' . $name . '"><option value="">— nije izabrano —</option>';

    foreach ($row['options'] as $slug => $label) {
        $html .= '<option value="' . $e($slug) . '"'
               . (in_array((string) $slug, $row['fixed'], true) ? ' selected' : '') . '>'
               . $e($label) . ' (' . $e($slug) . ')</option>';
    }

    return $html . '</select>';
};

$policySelect = static function (string $kind, array $row, array $policies) use ($e): string {
    $html = '<select name="map[' . $e($kind) . '][' . $e($row['ref']) . '][policy]">';

    foreach ($policies as $value => $label) {
        $html .= '<option value="' . $e($value) . '"'
               . ($row['write_policy'] === $value ? ' selected' : '') . '>'
               . $e($label) . '</option>';
    }

    return $html . '</select>';
};

$badge = ['relation' => 'veza', 'native' => 'nativno', 'eav' => 'atribut'];
?>

<h2 class="section-head">Mapiranje</h2>

<form method="post" action="/assetops/<?= $e($integration->id) ?>/mapping">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <?php foreach ($blocks as $kind => $spec):
        $rows    = $fields[$kind] ?? [];
        $current = $mapping[$kind];
    ?>
        <h3 class="section-head" style="font-size:15px"><?= $e($spec['naslov']) ?></h3>

        <div class="panel">
            <p class="hint" style="margin:0 0 12px"><?= $e($spec['opis']) ?></p>

            <div class="field">
                <label for="<?= $e($kind) ?>_type"><?= $e($spec['tipovi']) ?></label>
                <span class="hint">Određuje koje je vrste CI koji se pravi; ostalo se podešava u tabeli.</span>
                <select id="<?= $e($kind) ?>_type" name="<?= $e($kind) ?>_type">
                    <option value="">— nije izabran —</option>
                    <?php foreach ($types[$kind] ?? [] as $slug => $label): ?>
                        <option value="<?= $e($slug) ?>" <?= $current['type_slug'] === $slug ? 'selected' : '' ?>>
                            <?= $e($label) ?> (<?= $e($slug) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="panel table-scroll">
            <?php if ($rows === []): ?>
                <div class="empty">
                    <p>Nema ciljnih polja. Prikupi referentne podatke da bi se učitale definicije atributa.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Polje u AssetOPS-u</th>
                            <th>Vrsta</th>
                            <th>Vrednost</th>
                            <th>Pravilo</th>
                            <th>Ključ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $rail = $row['is_correlation']
                            ? 'rail-ok'
                            : ($row['write_policy'] === 'never'
                                ? ($row['required'] ? 'rail-bad' : 'rail-idle')
                                : 'rail-warn');
                    ?>
                        <tr>
                            <td class="rail <?= $rail ?> stacked">
                                <span>
                                    <?= $e($row['label']) ?>
                                    <?php if ($row['required'] === true): ?>
                                        <span class="tag">obavezno</span>
                                    <?php endif; ?>
                                </span>
                                <span class="sub data"><?= $e($row['key']) ?></span>
                            </td>
                            <td class="stacked">
                                <span class="tag"><?= $e($badge[$row['kind']] ?? $row['kind']) ?></span>
                                <?php if ($row['type'] !== ''): ?>
                                    <span class="sub data muted"><?= $e($row['type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= match ($row['value_mode']) {
                                    'list'     => $fixedSelect($kind, $row),
                                    'constant' => $constantInput($kind, $row),
                                    default    => $sourceSelect($kind, $row, $sourceGroups[$kind] ?? []),
                                } ?>
                            </td>
                            <td><?= $policySelect($kind, $row, $policies) ?></td>
                            <td>
                                <?php /* Kljuc nosi samo EAV atribut: nativnog polja za
                                         instance_uuid u AssetOPS-u nema. */ ?>
                                <?php if ($row['kind'] === 'eav'): ?>
                                    <label class="check">
                                        <input type="radio" name="corr[<?= $e($kind) ?>]"
                                               value="<?= $e($row['ref']) ?>"
                                               <?= $row['is_correlation'] ? 'checked' : '' ?>>
                                        ključ
                                    </label>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Sačuvaj mapiranje</button>
    </div>
</form>

<p class="note-block">
    Veze nose <b>slug</b>, ne ime — zato se za njih bira vrednost iz prikupljenog spiska umesto
    polja iz vCentera. Slug se ne izvodi iz imena: <span class="data">VM</span> ima slug
    <span class="data">kub</span>, <span class="data">Beograd</span> ima
    <span class="data">beograd-3</span>.
</p>

<p class="note-block">
    Ključ spajanja se uvek upisuje <b>samo pri kreiranju</b>, bez obzira na izabrano pravilo —
    vrednost po kojoj se CI prepoznaje ne sme da se menja. Najviše jedan po vrsti.
    AssetOPS ne ume da filtrira po vrednosti atributa, pa je atribut trajno sidro, a ne brz
    put do zapisa; spajanje se radi čitanjem svih CI-jeva i poređenjem lokalno.
</p>
