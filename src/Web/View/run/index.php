<?php
// src/Web/View/run/index.php
//
/** @var callable $e */
/** @var list<array<string,mixed>> $runs */

$statusLabel = [
    'queued'  => 'u redu čekanja',
    'running' => 'u toku',
    'success' => 'uspešno',
    'partial' => 'delimično',
    'failed'  => 'neuspešno',
];

$typeLabel = ['pending' => '—', 'ok' => 'ok', 'failed' => 'greška'];
?>

<div class="page-head">
    <div>
        <h1>Prikupljanja</h1>
        <p>Istorija discovery poslova, najnoviji prvi.</p>
    </div>
</div>

<div class="panel">
    <?php if ($runs === []): ?>

        <div class="empty">
            <p>Nijedno prikupljanje još nije pokrenuto.</p>
            <p class="muted">Pokreće se komandom <code class="data">bin/syncops discover &lt;id&gt;</code>.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Run</th>
                    <th>Integracija</th>
                    <th>Ishod</th>
                    <th>Klasteri</th>
                    <th>Hostovi</th>
                    <th>Mašine</th>
                    <th>Licence</th>
                    <th>Trajanje</th>
                    <th>Poziva</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run):
                $rail = match ($run['status']) {
                    'success'            => 'rail-ok',
                    'partial', 'running',
                    'queued'             => 'rail-warn',
                    'failed'             => 'rail-bad',
                    default              => 'rail-idle',
                };
            ?>
                <tr>
                    <td class="rail <?= $rail ?>">
                        <span class="data">#<?= $e($run['id']) ?></span>
                        <div class="muted"><?= $e($run['trigger_source']) ?></div>
                    </td>
                    <td><?= $e($run['integration_name']) ?></td>
                    <td>
                        <?= $e($statusLabel[$run['status']] ?? $run['status']) ?>
                        <div class="data muted"><?= $e($run['finished_at'] ?? $run['queued_at']) ?></div>
                    </td>
                    <td class="data">
                        <?= $e($run['clusters_found']) ?>
                        <?php if ($run['clusters_changed'] > 0): ?>
                            <div class="muted">+<?= $e($run['clusters_changed']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?= $e($run['hosts_found']) ?>
                        <?php if ($run['hosts_changed'] > 0): ?>
                            <div class="muted">+<?= $e($run['hosts_changed']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?= $e($run['vms_found']) ?>
                        <?php if ($run['vms_changed'] > 0): ?>
                            <div class="muted">+<?= $e($run['vms_changed']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?php if (($run['licenses_status'] ?? 'pending') === 'pending'): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?= $e($run['licenses_found']) ?>
                            <?php if ($run['licenses_changed'] > 0): ?>
                                <div class="muted">+<?= $e($run['licenses_changed']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $run['duration_seconds'] === null ? '—' : $e($run['duration_seconds']) . ' s' ?></td>
                    <td class="data"><?= $e($run['api_calls']) ?></td>
                </tr>
                <?php if ($run['error_message'] !== null): ?>
                    <tr>
                        <td colspan="9" class="muted" style="padding-top:0">
                            <pre class="diff"><?= $e(mb_strimwidth((string) $run['error_message'], 0, 600, '…')) ?></pre>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<p class="note-block">
    Kolona „+" pored broja pokazuje koliko je objekata u tom run-u bilo novo ili izmenjeno.
    Samo <strong>uspešan</strong> run daje pravo na zaključak da objekta koji nedostaje više nema —
    posle delimičnog, odsustvo može da znači i da prikupljanje nije stiglo do njega.
</p>
