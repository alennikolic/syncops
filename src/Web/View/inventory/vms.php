<?php
// src/Web/View/inventory/vms.php
//
/** @var callable $e */
/** @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $result */
/** @var array<string, string> $filters */
/** @var array{clusters: list<array<string,mixed>>, hosts: list<array<string,mixed>>, powers: list<string>, guests: list<string>} $options */
/** @var array<int, int> $lastRuns */

$guestLabel = [
    'available'         => 'Tools rade',
    'tools_not_running' => 'Tools ne rade',
    'powered_off'       => 'ugašena',
    'unavailable'       => 'nedostupno',
];

$powerLabel = [
    'POWERED_ON'  => 'uključena',
    'POWERED_OFF' => 'isključena',
    'SUSPENDED'   => 'suspendovana',
];

/** Imena klastera i hostova po moref-u, za oznake primenjenih uslova. */
$clusterNames = array_column($options['clusters'], 'name', 'moref');
$hostNames    = array_column($options['hosts'], 'name', 'moref');

$active = array_filter($filters, static fn (string $v): bool => $v !== '');

/** Veza koja uklanja jedan uslov a ostale zadržava. */
$without = static function (string $key): string {
    $params = $_GET;
    unset($params[$key], $params['page']);

    return $params === [] ? '/vms' : '/vms?' . http_build_query($params);
};

$gib = static fn (?int $bytes): string => $bytes === null
    ? '—'
    : (string) round($bytes / 1024 ** 3) . ' GiB';

/** vCenter vraca "VMX_19"; u tabeli je citljivije kao "v19". */
$hwVersion = static fn (?string $v): string => $v === null
    ? '—'
    : 'v' . ltrim(str_replace('VMX_', '', $v), '_');
?>

<div class="page-head">
    <div>
        <h1>Virtuelne mašine</h1>
        <p>
            <?= $e(number_format($result['total'], 0, ',', '.')) ?>
            <?= $active === [] ? 'ukupno' : 'odgovara uslovima' ?>
        </p>
    </div>
</div>

<form method="get" action="/vms" class="filters">
    <div class="filter-search">
        <input type="search" name="q" value="<?= $e($filters['search']) ?>"
               placeholder="Ime, hostname, IP adresa, UUID ili moref">
    </div>

    <select name="power" aria-label="Napajanje">
        <option value="">Svako napajanje</option>
        <?php foreach ($options['powers'] as $power): ?>
            <option value="<?= $e($power) ?>" <?= $filters['power'] === $power ? 'selected' : '' ?>>
                <?= $e($powerLabel[$power] ?? $power) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="guest" aria-label="Stanje gosta">
        <option value="">Svako stanje gosta</option>
        <?php foreach ($options['guests'] as $guest): ?>
            <option value="<?= $e($guest) ?>" <?= $filters['guest'] === $guest ? 'selected' : '' ?>>
                <?= $e($guestLabel[$guest] ?? $guest) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="cluster" aria-label="Klaster">
        <option value="">Svaki klaster</option>
        <?php foreach ($options['clusters'] as $cluster): ?>
            <option value="<?= $e($cluster['moref']) ?>" <?= $filters['cluster'] === $cluster['moref'] ? 'selected' : '' ?>>
                <?= $e($cluster['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="host" aria-label="Host">
        <option value="">Svaki host</option>
        <?php foreach ($options['hosts'] as $host): ?>
            <option value="<?= $e($host['moref']) ?>" <?= $filters['host'] === $host['moref'] ? 'selected' : '' ?>>
                <?= $e($host['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Primeni</button>
        <a href="/vms/export<?= $_GET === [] ? '' : '?' . $e(http_build_query(array_diff_key($_GET, ['page' => 1]))) ?>"
           class="btn">Izvezi CSV</a>
    </div>
</form>

<?php if ($active !== []): ?>
    <div class="chips">
        <span>Uslovi:</span>

        <?php if ($filters['search'] !== ''): ?>
            <a href="<?= $e($without('q')) ?>" class="chip">
                pretraga <b><?= $e($filters['search']) ?></b><span class="chip-x">×</span>
            </a>
        <?php endif; ?>

        <?php if ($filters['power'] !== ''): ?>
            <a href="<?= $e($without('power')) ?>" class="chip">
                <b><?= $e($powerLabel[$filters['power']] ?? $filters['power']) ?></b><span class="chip-x">×</span>
            </a>
        <?php endif; ?>

        <?php if ($filters['guest'] !== ''): ?>
            <a href="<?= $e($without('guest')) ?>" class="chip">
                <b><?= $e($guestLabel[$filters['guest']] ?? $filters['guest']) ?></b><span class="chip-x">×</span>
            </a>
        <?php endif; ?>

        <?php if ($filters['cluster'] !== ''): ?>
            <a href="<?= $e($without('cluster')) ?>" class="chip">
                klaster <b><?= $e($clusterNames[$filters['cluster']] ?? $filters['cluster']) ?></b><span class="chip-x">×</span>
            </a>
        <?php endif; ?>

        <?php if ($filters['host'] !== ''): ?>
            <a href="<?= $e($without('host')) ?>" class="chip">
                host <b><?= $e($hostNames[$filters['host']] ?? $filters['host']) ?></b><span class="chip-x">×</span>
            </a>
        <?php endif; ?>

        <a href="/vms" class="btn-link">Poništi sve</a>
    </div>
<?php endif; ?>

<div class="panel">
    <?php if ($result['rows'] === []): ?>

        <div class="empty">
            <p>Nema mašina koje odgovaraju zadatim uslovima.</p>
            <?php if ($active !== []): ?>
                <a href="/vms" class="btn">Poništi uslove</a>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Mašina</th>
                    <th>Stanje</th>
                    <th>Operativni sistem</th>
                    <th>CPU / RAM</th>
                    <th>Disk</th>
                    <th>Mreža</th>
                    <th>Smeštaj</th>
                    <th>Viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $vm):
                $lastRun = $lastRuns[(int) $vm['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $vm['last_run_id'] < $lastRun;

                $rail = $missing
                    ? 'rail-bad'
                    : match ($vm['power_state']) {
                        'POWERED_ON' => 'rail-ok',
                        'SUSPENDED'  => 'rail-warn',
                        default      => 'rail-idle',
                    };
            ?>
                <tr>
                    <td class="rail <?= $rail ?> stacked">
                        <a href="/vms/<?= $e($vm['id']) ?>"><?= $e($vm['name']) ?></a>
                        <span class="sub data"><?= $e($vm['moref']) ?></span>
                    </td>

                    <td class="stacked">
                        <span><?= $e($powerLabel[$vm['power_state']] ?? ($vm['power_state'] ?? '—')) ?></span>
                        <span class="sub"><?= $e($guestLabel[$vm['guest_state']] ?? '') ?></span>
                    </td>

                    <td class="stacked">
                        <?php if ($vm['guest_full_name'] !== null): ?>
                            <span><?= $e($vm['guest_full_name']) ?></span>
                            <span class="sub data"><?= $e($vm['guest_os'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="data"><?= $e($vm['guest_os'] ?? '—') ?></span>
                            <span class="sub">podešeno u vCenteru</span>
                        <?php endif; ?>
                    </td>

                    <td class="stacked">
                        <span class="data">
                            <?= $e($vm['cpu_count'] ?? '—') ?> vCPU
                        </span>
                        <span class="sub data">
                            <?= $vm['memory_mib'] === null ? '—' : $e(round((int) $vm['memory_mib'] / 1024, 1)) . ' GiB' ?>
                        </span>
                    </td>

                    <td class="stacked">
                        <span class="data"><?= $e($gib($vm['disk_capacity_bytes'] === null ? null : (int) $vm['disk_capacity_bytes'])) ?></span>
                        <span class="sub data">
                            <?= $vm['disk_count'] === null ? '' : $e($vm['disk_count']) . '×' ?>
                            <?= $vm['nic_count'] === null ? '' : ' · ' . $e($vm['nic_count']) . ' NIC' ?>
                        </span>
                    </td>

                    <td class="stacked">
                        <?php if ($vm['guest_ip_address'] !== null || $vm['guest_hostname'] !== null): ?>
                            <span class="data"><?= $e($vm['guest_ip_address'] ?? '—') ?></span>
                            <span class="sub data"><?= $e($vm['guest_hostname'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="stacked">
                        <span class="data"><?= $e($vm['host_name'] ?? $vm['host_moref'] ?? '—') ?></span>
                        <span class="sub"><?= $e($vm['cluster_name'] ?? '—') ?></span>
                    </td>

                    <td class="stacked">
                        <span class="data"><?= $e(substr((string) $vm['last_seen'], 0, 16)) ?></span>
                        <?php if ($missing): ?>
                            <span class="sub state-bad">nije u poslednjem run-u</span>
                        <?php else: ?>
                            <span class="sub data"><?= $e($hwVersion($vm['hardware_version'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_pager.php'; ?>
