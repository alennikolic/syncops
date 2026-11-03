<?php
// src/Web/View/inventory/clusters.php
//
/** @var callable $e */
/** @var list<array<string,mixed>> $clusters */
/** @var array<int, int> $lastRuns */
?>

<div class="page-head">
    <div>
        <h1>Klasteri</h1>
        <p><?= $e(count($clusters)) ?> klastera</p>
    </div>
</div>

<div class="panel">
    <?php if ($clusters === []): ?>

        <div class="empty">
            <p>Nema klastera. Pokreni prikupljanje da bi se tabela popunila.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Klaster</th>
                    <th>Datacentar</th>
                    <th>HA</th>
                    <th>DRS</th>
                    <th>Hostova</th>
                    <th>Mašina</th>
                    <th>Poslednji put viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clusters as $cluster):
                $lastRun = $lastRuns[(int) $cluster['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $cluster['last_run_id'] < $lastRun;
            ?>
                <tr>
                    <td class="rail <?= $missing ? 'rail-bad' : 'rail-ok' ?>">
                        <?= $e($cluster['name']) ?>
                        <div class="muted"><?= $e($cluster['integration_name']) ?></div>
                    </td>
                    <td><?= $e($cluster['datacenter_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($cluster['ha_enabled'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <span class="<?= $cluster['ha_enabled'] ? 'state-ok' : 'muted' ?>">
                                <?= $cluster['ha_enabled'] ? 'uključen' : 'isključen' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cluster['drs_enabled'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <span class="<?= $cluster['drs_enabled'] ? 'state-ok' : 'muted' ?>">
                                <?= $cluster['drs_enabled'] ? 'uključen' : 'isključen' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($cluster['host_count']) ?></td>
                    <td class="data"><?= $e($cluster['vm_count']) ?></td>
                    <td>
                        <span class="data"><?= $e($cluster['last_seen']) ?></span>
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
