<?php
// src/Web/View/inventory/vms.php
//
/** @var callable $e */
/** @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $result */
/** @var array<string, string> $filters */
/** @var array{clusters: list<array<string,mixed>>, powers: list<string>, guests: list<string>} $options */
/** @var array<int, int> $lastRuns */

$guestLabel = [
    'available'         => 'podaci iz gosta',
    'tools_not_running' => 'Tools ne rade',
    'powered_off'       => 'ugašena',
    'unavailable'       => 'nedostupno',
];

$powerLabel = [
    'POWERED_ON'  => 'uključena',
    'POWERED_OFF' => 'isključena',
    'SUSPENDED'   => 'suspendovana',
];
?>

<div class="page-head">
    <div>
        <h1>Virtuelne mašine</h1>
        <p><?= $e(number_format($result['total'], 0, ',', '.')) ?> ukupno<?= $filters['search'] !== '' || $filters['power'] !== '' || $filters['guest'] !== '' || $filters['cluster'] !== '' ? ' uz zadate uslove' : '' ?></p>
    </div>
</div>

<form method="get" action="/vms" class="filters">
    <input type="search" name="q" value="<?= $e($filters['search']) ?>"
           placeholder="Ime, hostname, IP ili UUID">

    <select name="power">
        <option value="">Svako napajanje</option>
        <?php foreach ($options['powers'] as $power): ?>
            <option value="<?= $e($power) ?>" <?= $filters['power'] === $power ? 'selected' : '' ?>>
                <?= $e($powerLabel[$power] ?? $power) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="guest">
        <option value="">Svaki gost</option>
        <?php foreach ($options['guests'] as $guest): ?>
            <option value="<?= $e($guest) ?>" <?= $filters['guest'] === $guest ? 'selected' : '' ?>>
                <?= $e($guestLabel[$guest] ?? $guest) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="cluster">
        <option value="">Svaki klaster</option>
        <?php foreach ($options['clusters'] as $cluster): ?>
            <option value="<?= $e($cluster['moref']) ?>" <?= $filters['cluster'] === $cluster['moref'] ? 'selected' : '' ?>>
                <?= $e($cluster['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn">Primeni</button>
    <a href="/vms" class="btn-link">Poništi</a>
</form>

<div class="panel">
    <?php if ($result['rows'] === []): ?>

        <div class="empty">
            <p>Nema mašina koje odgovaraju zadatim uslovima.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Mašina</th>
                    <th>Napajanje</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Gost</th>
                    <th>Host</th>
                    <th>Poslednji put viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $vm):
                $lastRun = $lastRuns[(int) $vm['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $vm['last_run_id'] < $lastRun;

                $rail = $missing
                    ? 'rail-bad'
                    : match ($vm['power_state']) {
                        'POWERED_ON'  => 'rail-ok',
                        'SUSPENDED'   => 'rail-warn',
                        default       => 'rail-idle',
                    };
            ?>
                <tr>
                    <td class="rail <?= $rail ?>">
                        <a href="/vms/<?= $e($vm['id']) ?>"><?= $e($vm['name']) ?></a>
                        <div class="data muted"><?= $e($vm['guest_os'] ?? '—') ?></div>
                    </td>
                    <td><?= $e($powerLabel[$vm['power_state']] ?? ($vm['power_state'] ?? '—')) ?></td>
                    <td class="data"><?= $e($vm['cpu_count'] ?? '—') ?></td>
                    <td class="data"><?= $vm['memory_mib'] === null ? '—' : $e(round((int) $vm['memory_mib'] / 1024, 1)) . ' GiB' ?></td>
                    <td>
                        <?php if ($vm['guest_hostname'] !== null || $vm['guest_ip_address'] !== null): ?>
                            <span class="data"><?= $e($vm['guest_hostname'] ?? '—') ?></span>
                            <div class="data muted"><?= $e($vm['guest_ip_address'] ?? '—') ?></div>
                        <?php else: ?>
                            <span class="muted"><?= $e($guestLabel[$vm['guest_state']] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="data"><?= $e($vm['host_name'] ?? $vm['host_moref'] ?? '—') ?></span>
                        <div class="muted"><?= $e($vm['cluster_name'] ?? '—') ?></div>
                    </td>
                    <td>
                        <span class="data"><?= $e($vm['last_seen']) ?></span>
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

<?php require __DIR__ . '/../_pager.php'; ?>
