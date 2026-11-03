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
                    <th>CPU / RAM</th>
                    <th>ESXi</th>
                    <th>Viđeno</th>
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
                    <td class="stacked">
                        <?php if ($host['hardware_source'] === null): ?>
                            <span class="muted">nije prikupljeno</span>
                        <?php else: ?>
                            <span><?= $e(trim(($host['vendor'] ?? '') . ' ' . ($host['model'] ?? '')) ?: '—') ?></span>
                            <span class="sub data">SN <?= $e($host['serial_number'] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="stacked">
                        <?php if ($host['cpu_cores'] !== null || $host['memory_bytes'] !== null): ?>
                            <span class="data">
                                <?= $e($host['cpu_sockets'] ?? '?') ?>&times;<?= $e($host['cpu_cores'] ?? '?') ?> jezgara
                            </span>
                            <span class="sub data">
                                <?= $host['memory_bytes'] === null ? '—' : $e(round((int) $host['memory_bytes'] / 1024 ** 3)) . ' GiB' ?>
                            </span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="stacked">
                        <?php if ($host['esxi_version'] !== null): ?>
                            <span class="data">ESXi <?= $e($host['esxi_version']) ?></span>
                            <span class="sub data">build <?= $e($host['esxi_build'] ?? '—') ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
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
        Hardverski podaci se prikupljaju preko SOAP krajnje tačke <code class="data">/sdk</code>, jer ih
        REST API ne daje. Kada za neki host piše „nije prikupljeno", razlog je jedan od tri: host je
        odvojen pa nema šta da javi, <code class="data">/sdk</code> nije dostupan, ili nalog nema pravo
        čitanja. Razlog stoji uz odgovarajuće prikupljanje, na stranici
        <a href="/runs">Prikupljanja</a>.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../_pager.php'; ?>
