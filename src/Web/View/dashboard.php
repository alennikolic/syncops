<?php
// src/Web/View/dashboard.php
//
/** @var callable $e */
/** @var array<string, int> $counts */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var array<int, array<string, mixed>> $lastRuns */

/** Boja kicme reda prema stanju poslednjeg run-a. */
$railFor = static function (?array $run, bool $isActive): string {
    if (!$isActive) {
        return 'rail-idle';
    }

    return match ($run['status'] ?? null) {
        'success'            => 'rail-ok',
        'partial', 'running',
        'queued'             => 'rail-warn',
        'failed'             => 'rail-bad',
        default              => 'rail-idle',
    };
};

$statusLabel = [
    'queued'  => 'u redu čekanja',
    'running' => 'u toku',
    'success' => 'uspešno',
    'partial' => 'delimično',
    'failed'  => 'neuspešno',
];
?>

<div class="page-head">
    <div>
        <h1>Pregled</h1>
        <p>Stanje integracija i količina prikupljenih podataka.</p>
    </div>
</div>

<div class="counts">
    <?php foreach ($counts as $label => $value): ?>
        <div class="count">
            <b><?= $e(number_format($value, 0, ',', '.')) ?></b>
            <small><?= $e($label) ?></small>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <?php if ($integrations === []): ?>

        <div class="empty">
            <p>Još nema nijednog povezanog vCentera.</p>
            <a href="/integrations/new" class="btn btn-primary">Dodaj vCenter</a>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>vCenter</th>
                    <th>Poslednje prikupljanje</th>
                    <th>Klasteri</th>
                    <th>Hostovi</th>
                    <th>Mašine</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($integrations as $i):
                $run = $lastRuns[$i->id] ?? null;
            ?>
                <tr>
                    <td class="rail <?= $railFor($run, $i->isActive) ?>">
                        <a href="/integrations/<?= $e($i->id) ?>/edit"><?= $e($i->name) ?></a>
                        <?php if (!$i->isActive): ?>
                            <span class="muted">— isključena</span>
                        <?php endif; ?>
                        <div class="data muted"><?= $e($i->hostname) ?></div>
                    </td>
                    <td>
                        <?php if ($run === null): ?>
                            <span class="muted">nije pokretano</span>
                        <?php else: ?>
                            <?= $e($statusLabel[$run['status']] ?? $run['status']) ?>
                            <div class="data muted">
                                <?= $e($run['finished_at'] ?? $run['queued_at']) ?> UTC
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $run === null ? '<span class="muted">—</span>' : $e($run['clusters_found']) ?></td>
                    <td class="data"><?= $run === null ? '<span class="muted">—</span>' : $e($run['hosts_found']) ?></td>
                    <td class="data"><?= $run === null ? '<span class="muted">—</span>' : $e($run['vms_found']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
