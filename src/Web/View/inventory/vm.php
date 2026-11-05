<?php
// src/Web/View/inventory/vm.php
//
// Detalji jedne masine, sa journalom promena.
//
/** @var callable $e */
/** @var array<string, mixed> $vm */
/** @var list<array<string,mixed>> $changes */
/** @var array<int, int> $lastRuns */

$lastRun = $lastRuns[(int) $vm['integration_id']] ?? null;
$missing = $lastRun !== null && (int) $vm['last_run_id'] < $lastRun;

$powerLabel = [
    'POWERED_ON'  => 'uključena',
    'POWERED_OFF' => 'isključena',
    'SUSPENDED'   => 'suspendovana',
];

$guestLabel = [
    'available'         => 'VMware Tools rade',
    'tools_not_running' => 'VMware Tools ne rade',
    'powered_off'       => 'mašina je ugašena',
    'unavailable'       => 'nedostupno',
];

/** Prikaz vrednosti koja sme da bude prazna. */
$val = static function (mixed $v) use ($e): string {
    return ($v === null || $v === '')
        ? '<span class="muted">—</span>'
        : '<span class="data">' . $e($v) . '</span>';
};

$bytes = static function (mixed $b) use ($e): string {
    if ($b === null) {
        return '<span class="muted">—</span>';
    }

    return '<span class="data">' . $e(round((int) $b / 1024 ** 3, 1)) . ' GiB</span>';
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($vm['name']) ?></h1>
        <p>
            <?= $e($powerLabel[$vm['power_state']] ?? ($vm['power_state'] ?? 'stanje nepoznato')) ?>
            &middot; <?= $e($vm['integration_name']) ?>
        </p>
    </div>
    <a href="/vms" class="btn">Nazad na listu</a>
</div>

<?php if ($missing): ?>
    <div class="flash flash-error">
        Ova mašina nije viđena u poslednjem uspešnom prikupljanju. Možda je obrisana iz vCentera,
        ali SyncOPS taj zaključak ne donosi — red ostaje u bazi.
    </div>
<?php endif; ?>

<?php if (($vm['notes'] ?? '') !== ''): ?>
    <section class="panel notes">
        <h2>Notes</h2>
        <?php /* Tekst je kakav je upisan u vCenteru: prelomi redova se cuvaju,
                 a escape-ovanje ide kroz $e, pa HTML iz Notes-a ne moze da
                 pobegne u stranicu. */ ?>
        <div class="notes-body"><?= nl2br($e($vm['notes']), false) ?></div>
        <p class="hint">Slobodan tekst iz vCentera — SyncOPS ga ne tumači.</p>
    </section>
<?php endif; ?>

<div class="detail-grid">

    <section class="panel">
        <h2>Identifikatori</h2>
        <dl>
            <dt>instance_uuid</dt><dd><?= $val($vm['instance_uuid']) ?></dd>
            <dt>bios_uuid</dt><dd><?= $val($vm['bios_uuid']) ?></dd>
            <dt>moref</dt><dd><?= $val($vm['moref']) ?></dd>
        </dl>
        <p class="hint">
            Za povezivanje sa drugim izvorima koristi <code class="data">bios_uuid</code> —
            hostname koji gost prijavljuje ume da bude netačan.
        </p>
    </section>

    <section class="panel">
        <h2>Resursi</h2>
        <dl>
            <dt>vCPU</dt><dd><?= $val($vm['cpu_count']) ?></dd>
            <dt>Jezgara po soketu</dt><dd><?= $val($vm['cpu_cores_per_socket']) ?></dd>
            <dt>Memorija</dt><dd><?= $vm['memory_mib'] === null ? '<span class="muted">—</span>' : '<span class="data">' . $e(round((int) $vm['memory_mib'] / 1024, 1)) . ' GiB</span>' ?></dd>
            <dt>Diskova</dt><dd><?= $val($vm['disk_count']) ?></dd>
            <dt>Ukupan kapacitet</dt><dd><?= $bytes($vm['disk_capacity_bytes']) ?></dd>
            <dt>Mrežnih kartica</dt><dd><?= $val($vm['nic_count']) ?></dd>
            <dt>Verzija hardvera</dt><dd><?= $val($vm['hardware_version']) ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2>Operativni sistem</h2>
        <dl>
            <dt>Podešen u vCenteru</dt><dd><?= $val($vm['guest_os']) ?></dd>
            <dt>Prijavljen iz gosta</dt><dd><?= $val($vm['guest_full_name']) ?></dd>
            <dt>Porodica</dt><dd><?= $val($vm['guest_os_family']) ?></dd>
        </dl>
        <?php if ($vm['guest_os'] !== null && $vm['guest_full_name'] !== null): ?>
            <p class="hint">Gornja vrednost je ono što je izabrano pri kreiranju mašine, donja je ono što OS zaista jeste.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Mreža i gost</h2>
        <dl>
            <dt>Hostname</dt><dd><?= $val($vm['guest_hostname']) ?></dd>
            <dt>IP adresa</dt><dd><?= $val($vm['guest_ip_address']) ?></dd>
            <dt>Stanje gosta</dt><dd><?= $e($guestLabel[$vm['guest_state']] ?? ($vm['guest_state'] ?? '—')) ?></dd>
            <dt>Pročitano</dt><dd><?= $val($vm['guest_seen_at']) ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2>Smeštaj</h2>
        <dl>
            <dt>Host</dt><dd><?= $val($vm['host_name'] ?? $vm['host_moref']) ?></dd>
            <dt>Klaster</dt><dd><?= $val($vm['cluster_name'] ?? $vm['cluster_moref']) ?></dd>
            <dt>Datacentar</dt><dd><?= $val($vm['datacenter_moref']) ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2>Evidencija</h2>
        <dl>
            <dt>Prvi put viđeno</dt><dd><?= $val($vm['first_seen']) ?></dd>
            <dt>Poslednji put viđeno</dt><dd><?= $val($vm['last_seen']) ?></dd>
            <dt>Prvi run</dt><dd><?= $val($vm['first_run_id']) ?></dd>
            <dt>Poslednji run</dt><dd><?= $val($vm['last_run_id']) ?></dd>
        </dl>
    </section>

</div>

<h2 class="section-head">Promene<?= $changes === [] ? '' : ' (' . $e(count($changes)) . ')' ?></h2>

<div class="panel">
    <?php if ($changes === []): ?>

        <div class="empty">
            <p>Nijedna promena nije zabeležena otkad se ova mašina prati.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Kada</th>
                    <th>Run</th>
                    <th>Pre</th>
                    <th>Posle</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($changes as $change): ?>
                <tr>
                    <td class="data"><?= $e($change['changed_at']) ?></td>
                    <td class="data">#<?= $e($change['run_id']) ?></td>
                    <td><pre class="diff"><?= $e(json_encode(
                        json_decode((string) $change['old_values'], true),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )) ?></pre></td>
                    <td><pre class="diff"><?= $e(json_encode(
                        json_decode((string) $change['new_values'], true),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/_raw.php'; ?>
