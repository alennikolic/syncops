<?php
// src/Web/View/inventory/datastores.php
//
// Datastore-ovi prikupljeni sa vCentera.
//
// Nema filtera ni stranicenja, kao ni kod klastera: datastore-ova je po
// vCenteru desetak do nekoliko desetina.
//
// Redosled dolazi iz upita — najpuniji su gore, jer se zbog njih i gleda ovaj
// ekran.
//
/** @var callable $e */
/** @var list<array<string,mixed>> $datastores */
/** @var array<int, int> $lastRuns */

/** Bajtovi u citljiv oblik. TiB tek od hiljadu GiB navise. */
$velicina = static function (mixed $bytes): string {
    if ($bytes === null || !is_numeric($bytes)) {
        return '—';
    }

    $gib = (float) $bytes / 1073741824;

    return $gib >= 1024
        ? number_format($gib / 1024, 2, ',', '.') . ' TiB'
        : number_format($gib, 1, ',', '.') . ' GiB';
};

$upozorenje = 0;

foreach ($datastores as $datastore) {
    if ($datastore['used_ratio'] !== null && (float) $datastore['used_ratio'] >= 0.85) {
        $upozorenje++;
    }
}
?>

<div class="page-head">
    <div>
        <h1>Datastore</h1>
        <p>
            <?= $e(count($datastores)) ?> datastore-ova
            <?php if ($upozorenje > 0): ?>
                &middot; <span class="state-warn"><?= $e($upozorenje) ?> preko 85%</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="panel">
    <?php if ($datastores === []): ?>

        <div class="empty">
            <p>Nema datastore-ova. Pokreni prikupljanje da bi se tabela popunila.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Datastore</th>
                    <th>Tip</th>
                    <th>Popunjeno</th>
                    <th>Slobodno</th>
                    <th>Kapacitet</th>
                    <th>Tanko dodeljeno</th>
                    <th>Hostova</th>
                    <th>Poslednji put viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($datastores as $datastore):
                $lastRun = $lastRuns[(int) $datastore['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $datastore['last_run_id'] < $lastRun;

                $odnos   = $datastore['used_ratio'] === null ? null : (float) $datastore['used_ratio'];
                $procenat = $odnos === null ? null : (int) round($odnos * 100);

                $nedostupan = $datastore['accessible'] !== null && (int) $datastore['accessible'] === 0;
                $uOdrzavanju = ($datastore['maintenance_mode'] ?? 'normal') !== 'normal';

                $rail = match (true) {
                    $missing, $nedostupan           => 'rail-bad',
                    $uOdrzavanju                    => 'rail-warn',
                    $odnos !== null && $odnos >= 0.9 => 'rail-bad',
                    $odnos !== null && $odnos >= 0.85 => 'rail-warn',
                    default                          => 'rail-ok',
                };

                // Tanko dodeljeno preko kapaciteta: obecano je vise nego sto
                // postoji, pa vredi istaci.
                $prekoracenje = $datastore['uncommitted_bytes'] !== null
                    && $datastore['free_bytes'] !== null
                    && (int) $datastore['uncommitted_bytes'] > (int) $datastore['free_bytes'];
            ?>
                <tr>
                    <td class="rail <?= $rail ?>">
                        <?= $e($datastore['name']) ?>
                        <div class="muted"><?= $e($datastore['integration_name']) ?></div>
                        <?php if ($nedostupan): ?>
                            <div class="state-bad">nedostupan</div>
                        <?php elseif ($uOdrzavanju): ?>
                            <div class="state-warn"><?= $e($datastore['maintenance_mode']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?= $e($datastore['type'] ?? '—') ?>
                        <?php if ($datastore['vmfs_version'] !== null): ?>
                            <div class="muted"><?= $e($datastore['vmfs_version']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?php if ($procenat === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <span class="<?= $procenat >= 90 ? 'state-bad' : ($procenat >= 85 ? 'state-warn' : '') ?>">
                                <?= $e($procenat) ?>%
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($velicina($datastore['free_bytes'])) ?></td>
                    <td class="data"><?= $e($velicina($datastore['capacity_bytes'])) ?></td>
                    <td class="data">
                        <?= $e($velicina($datastore['uncommitted_bytes'])) ?>
                        <?php if ($prekoracenje): ?>
                            <div class="state-warn">preko slobodnog</div>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $datastore['host_count'] === null ? '—' : $e($datastore['host_count']) ?></td>
                    <td>
                        <span class="data"><?= $e($datastore['last_seen']) ?></span>
                        <?php if ($missing): ?>
                            <div class="state-bad">nije viđeno u poslednjem prikupljanju</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
