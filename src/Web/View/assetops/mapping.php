<?php
// src/Web/View/assetops/mapping.php
//
// Cime vCenter objekat postaje CI u AssetOPS-u.
//
// Po vrsti izvora: prvo tip CI-ja, pa tabela sa svim ciljnim poljima. U
// tabeli su tri vrste ciljeva i razlikuju se po tome odakle im vrednost:
//
//   veza      iz prikupljenog spiska (status, lokacija, grupe) — salje se slug
//   nativno   polje CI-ja; najcesce iz vCentera, ponekad rucno upisano
//   atribut   EAV, iz vCentera, oblik zavisi od tipa atributa
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var array<string, array<string,mixed>> $mapping */
/** @var array<string, array<string,string>> $types */
/** @var array<string, list<array<string,mixed>>> $fields */
/** @var array<string, array<string, array<string,string>>> $sourceGroups */
/** @var array<string, string> $policies */
/** @var string $csrf */

$switchTo = '/assetops/mapping';

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

/** Ručno upisana vrednost, za polja kojima u vCenteru nema parnjaka. */
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

$badge   = ['relation' => 'veza', 'native' => 'nativno', 'eav' => 'atribut'];
$anyRows = array_sum(array_map('count', $fields)) > 0;
?>

<div class="page-head">
    <div>
        <h1>Mapiranje</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            čime vCenter objekat postaje CI
        </p>
    </div>
    <div class="head-actions">
        <?php require __DIR__ . '/_switcher.php'; ?>
        <a href="/assetops" class="btn">Referentni podaci</a>
    </div>
</div>

<?php if (!$anyRows): ?>
    <div class="panel">
        <div class="empty">
            <p>
                Nema ciljnih polja. Otvori <a href="/assetops">Referentne podatke</a> i klikni
                „Prikupi sada" — tek tada se znaju tipovi, lokacije, statusi i definicije atributa
                iz kojih se ovde bira.
            </p>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="/assetops/<?= $e($integration->id) ?>/mapping">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <?php foreach ($blocks as $kind => $spec):
        $rows    = $fields[$kind] ?? [];
        $current = $mapping[$kind];
    ?>
        <h2 class="section-head"><?= $e($spec['naslov']) ?></h2>

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

        <?php if ($rows !== []): ?>
            <div class="panel table-scroll">
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
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Sačuvaj mapiranje</button>
    </div>
</form>

<p class="note-block">
    Veze nose <b>slug</b>, ne ime — zato se za njih bira vrednost iz prikupljenog spiska umesto
    polja iz vCentera. Spiskovi se vide na stranici
    <a href="/assetops">Referentni podaci</a>.
</p>

<p class="note-block">
    Ključ spajanja se uvek upisuje <b>samo pri kreiranju</b>, bez obzira na izabrano pravilo —
    vrednost po kojoj se CI prepoznaje ne sme da se menja. Najviše jedan po vrsti.
    AssetOPS ne ume da filtrira po vrednosti atributa, pa je atribut trajno sidro, a ne brz
    put do zapisa; spajanje se radi čitanjem svih CI-jeva i poređenjem lokalno.
</p>
