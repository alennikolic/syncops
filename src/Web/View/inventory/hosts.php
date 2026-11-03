<?php
// src/Web/View/inventory/hosts.php
//
/** @var callable $e */
/** @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $result */
/** @var array<string, string> $filters */
/** @var array<int, int> $lastRuns */

$stateLabel = [
    'CONNECTED'      => 'povezan',
    'DISCONNECTED'   => 'odvojen',
    'NOT_RESPONDING' => 'ne odgovara',
];
?>

<div class="page-head">
    <div>
        <h1>Hostovi</h1>
        <p><?= $e($result['total']) ?> ESXi hostova</p>
    </div>
</div>

<form method="get" action="/hosts" class="filters">
    <input type="search" name="q" value="<?= $e($filters['search']) ?>"
           placeholder="Ime, model ili serijski broj">
    <button type="submit" class="btn">Primeni</button>
    <?php if ($filters['search'] !== ''): ?>
        <a href="/hosts" class="btn-link">Poništi</a>
    <?php endif; ?>
</form>

<div class="panel">
    <?php if ($result['rows'] === []): ?>

        <div class="empty">
            <p>Nema hostova. Pokreni prikupljanje da bi se tabela popunila.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Host</th>
                    <th>Stanje</th>
                    <th>Klaster</th>
                    <th>Mašina</th>
                    <th>Hardver</th>
                    <th>Poslednji put viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $host):
                $lastRun = $lastRuns[(int) $host['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $host['last_run_id'] < $lastRun;

                $rail = $missing
                    ? 'rail-bad'
                    : match ($host['connection_state']) {
                        'CONNECTED'      => 'rail-ok',
                        'NOT_RESPONDING' => 'rail-warn',
                        default          => 'rail-idle',
                    };
            ?>
                <tr>
                    <td class="rail <?= $rail ?>">
                        <span class="data"><?= $e($host['name']) ?></span>
                        <div class="muted"><?= $e($host['integration_name']) ?></div>
                    </td>
                    <td>
                        <?= $e($stateLabel[$host['connection_state']] ?? ($host['connection_state'] ?? '—')) ?>
                        <?php if ($host['power_state'] === null): ?>
                            <div class="muted">napajanje nepoznato</div>
                        <?php endif; ?>
                    </td>
                    <td><?= $e($host['cluster_name'] ?? '—') ?></td>
                    <td class="data"><?= $e($host['vm_count']) ?></td>
                    <td>
                        <?php if ($host['hardware_source'] === null): ?>
                            <span class="muted">nije prikupljeno</span>
                        <?php else: ?>
                            <span class="data"><?= $e(trim(($host['vendor'] ?? '') . ' ' . ($host['model'] ?? '')) ?: '—') ?></span>
                            <div class="data muted"><?= $e($host['serial_number'] ?? '—') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="data"><?= $e($host['last_seen']) ?></span>
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

<?php if ($result['rows'] !== [] && array_filter($result['rows'], static fn ($h) => $h['hardware_source'] === null) !== []): ?>
    <p class="note-block">
        Hardverski podaci su prazni jer ih vSphere REST API ne daje — ni proizvođača, ni model,
        ni serijski broj. Popuniće se kada se doda SOAP klijent.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../_pager.php'; ?>
