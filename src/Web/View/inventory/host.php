<?php
// src/Web/View/inventory/host.php
//
// Detalji jednog ESXi hosta: hardver iz SOAP-a, mašine koje sede na njemu i
// journal promena.
//
/** @var callable $e */
/** @var array<string, mixed> $host */
/** @var list<array<string,mixed>> $vms */
/** @var array<string, list<array<string,mixed>>> $devices vrsta => redovi */
/** @var list<array<string,mixed>> $changes */
/** @var array<int, int> $lastRuns */

$lastRun = $lastRuns[(int) $host['integration_id']] ?? null;
$missing = $lastRun !== null && (int) $host['last_run_id'] < $lastRun;

$stateLabel = [
    'CONNECTED'      => 'povezan',
    'DISCONNECTED'   => 'odvojen',
    'NOT_RESPONDING' => 'ne odgovara',
];

$powerLabel = [
    'POWERED_ON'  => 'uključen',
    'POWERED_OFF' => 'isključen',
    'STANDBY'     => 'u pripravnosti',
];

$vmPowerLabel = [
    'POWERED_ON'  => 'uključena',
    'POWERED_OFF' => 'isključena',
    'SUSPENDED'   => 'suspendovana',
];

$val = static function (mixed $v) use ($e): string {
    return ($v === null || $v === '')
        ? '<span class="muted">—</span>'
        : '<span class="data">' . $e($v) . '</span>';
};

$gib = static function (mixed $bytes) use ($e): string {
    return $bytes === null
        ? '<span class="muted">—</span>'
        : '<span class="data">' . $e(round((int) $bytes / 1024 ** 3)) . ' GiB</span>';
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($host['name']) ?></h1>
        <p>
            <?= $e($stateLabel[$host['connection_state']] ?? ($host['connection_state'] ?? 'stanje nepoznato')) ?>
            &middot; <?= $e($host['cluster_name'] ?? 'bez klastera') ?>
            &middot; <?= $e($host['integration_name']) ?>
        </p>
    </div>
    <a href="/hosts" class="btn">Nazad na listu</a>
</div>

<?php if ($missing): ?>
    <div class="flash flash-error">
        Ovaj host nije viđen u poslednjem uspešnom prikupljanju. Možda je uklonjen iz vCentera,
        ali SyncOPS taj zaključak ne donosi — red ostaje u bazi.
    </div>
<?php endif; ?>

<?php if ($host['hardware_source'] === null): ?>
    <div class="flash flash-error">
        Hardverski podaci za ovaj host nisu prikupljeni. Razlog je jedan od tri: host je odvojen pa
        nema šta da javi, SOAP krajnja tačka <code class="data">/sdk</code> nije dostupna, ili nalog
        nema pravo čitanja. Razlog stoji uz odgovarajuće prikupljanje, na stranici
        <a href="/runs">Prikupljanja</a>.
    </div>
<?php endif; ?>

<div class="detail-grid">

    <section class="panel">
        <h2>Hardver</h2>
        <dl>
            <dt>Proizvođač</dt><dd><?= $val($host['vendor']) ?></dd>
            <dt>Model</dt><dd><?= $val($host['model']) ?></dd>
            <dt>Serijski broj</dt><dd><?= $val($host['serial_number']) ?></dd>
            <dt>Inventarski broj</dt><dd><?= $val($host['asset_tag']) ?></dd>
            <dt>Hardverski UUID</dt><dd><?= $val($host['hardware_uuid']) ?></dd>
            <dt>BIOS</dt><dd><?= $val($host['bios_version']) ?></dd>
        </dl>
        <?php if ($host['hardware_source'] !== null): ?>
            <p class="hint">Prikupljeno preko <?= $e(strtoupper((string) $host['hardware_source'])) ?> krajnje tačke.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Procesor i memorija</h2>
        <dl>
            <dt>Model procesora</dt><dd><?= $val($host['cpu_model']) ?></dd>
            <dt>Soketa</dt><dd><?= $val($host['cpu_sockets']) ?></dd>
            <dt>Jezgara ukupno</dt><dd><?= $val($host['cpu_cores']) ?></dd>
            <dt>Niti ukupno</dt><dd><?= $val($host['cpu_threads']) ?></dd>
            <dt>Takt</dt><dd><?= $host['cpu_mhz'] === null ? '<span class="muted">—</span>' : '<span class="data">' . $e($host['cpu_mhz']) . ' MHz</span>' ?></dd>
            <dt>Memorija</dt><dd><?= $gib($host['memory_bytes']) ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2>ESXi</h2>
        <dl>
            <dt>Verzija</dt><dd><?= $val($host['esxi_version']) ?></dd>
            <dt>Build</dt><dd><?= $val($host['esxi_build']) ?></dd>
            <dt>Stanje veze</dt><dd><?= $e($stateLabel[$host['connection_state']] ?? ($host['connection_state'] ?? '—')) ?></dd>
            <dt>Napajanje</dt><dd>
                <?= $host['power_state'] === null
                    ? '<span class="muted">—</span>'
                    : $e($powerLabel[$host['power_state']] ?? $host['power_state']) ?>
            </dd>
        </dl>
        <?php if ($host['power_state'] === null && $host['connection_state'] === 'NOT_RESPONDING'): ?>
            <p class="hint">vCenter ne javlja napajanje dok host ne odgovara — to nije greška u prikupljanju.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Smeštaj</h2>
        <dl>
            <dt>Klaster</dt><dd><?= $val($host['cluster_name'] ?? $host['cluster_moref']) ?></dd>
            <dt>Datacentar</dt><dd><?= $val($host['datacenter_moref']) ?></dd>
            <dt>moref</dt><dd><?= $val($host['moref']) ?></dd>
            <dt>Mašina na hostu</dt><dd><?= $val($host['vm_count']) ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2>Evidencija</h2>
        <dl>
            <dt>Prvi put viđeno</dt><dd><?= $val($host['first_seen']) ?></dd>
            <dt>Poslednji put viđeno</dt><dd><?= $val($host['last_seen']) ?></dd>
            <dt>Prvi run</dt><dd><?= $val($host['first_run_id']) ?></dd>
            <dt>Poslednji run</dt><dd><?= $val($host['last_run_id']) ?></dd>
        </dl>
    </section>

</div>

<?php
// ---------------------------------------------------------------------
// Uredjaji hosta
//
// Citaju se u zasebnom prolazu, ne uz ostala polja hosta: ta polja
// uvecavaju odgovor vCentera vise od dvesta puta, a hostovi se citaju i
// po datacentru. Prazno ovde znaci da prikupljanje jos nije proslo posle
// dodavanja ove tabele, ne da host nema mrezu.
// ---------------------------------------------------------------------
$ukupnoUredjaja = array_sum(array_map('count', $devices));

/** Vrednost ili crtica, za celije tabele. */
$cell = static fn (mixed $v): string => ($v === null || $v === '') ? '—' : $e($v);
?>

<h2 class="section-head">
    Uređaji<?= $ukupnoUredjaja === 0 ? '' : ' (' . $e($ukupnoUredjaja) . ')' ?>
</h2>

<?php if ($ukupnoUredjaja === 0): ?>

    <div class="panel">
        <div class="empty">
            <p>
                Nema prikupljenih uređaja. Pokreni prikupljanje da bi se
                mrežne kartice, VMkernel adapteri, svičevi, port grupe i HBA
                pojavili ovde.
            </p>
        </div>
    </div>

<?php else: ?>

    <?php if (($devices['vmk'] ?? []) !== []): ?>
        <h3 class="section-head">VMkernel adapteri</h3>
        <div class="panel">
            <table>
                <thead><tr>
                    <th>Adapter</th><th>IP adresa</th><th>Maska</th><th>Gateway</th>
                    <th>DHCP</th><th>MTU</th><th>Port grupa</th><th>MAC</th>
                </tr></thead>
                <tbody>
                <?php foreach ($devices['vmk'] as $d): ?>
                    <tr>
                        <td class="data"><?= $e($d['device']) ?></td>
                        <td class="data"><?= $cell($d['ip_address']) ?></td>
                        <td class="data"><?= $cell($d['subnet_mask']) ?></td>
                        <td class="data"><?= $cell($d['gateway']) ?></td>
                        <td><?= $d['dhcp'] === null ? '—' : ((int) $d['dhcp'] === 1 ? 'da' : 'ne') ?></td>
                        <td class="data"><?= $cell($d['mtu']) ?></td>
                        <td><?= $cell($d['port_group']) ?></td>
                        <td class="data"><?= $cell($d['mac']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (($devices['pnic'] ?? []) !== []): ?>
        <h3 class="section-head">Mrežne kartice</h3>
        <div class="panel">
            <table>
                <thead><tr>
                    <th>Kartica</th><th>Veza</th><th>MAC</th>
                    <th>Drajver</th><th>Firmver</th><th>PCI</th>
                </tr></thead>
                <tbody>
                <?php foreach ($devices['pnic'] as $d):
                    // linkSpeed izostaje kada je link dole — to je podatak, ne propust.
                    $dole = $d['speed_mb'] === null;
                ?>
                    <tr>
                        <td class="rail <?= $dole ? 'rail-warn' : 'rail-ok' ?>"><?= $e($d['device']) ?></td>
                        <td class="data">
                            <?php if ($dole): ?>
                                <span class="state-warn">bez veze</span>
                            <?php else: ?>
                                <?= $e((int) $d['speed_mb'] >= 1000
                                    ? (int) ((int) $d['speed_mb'] / 1000) . ' Gb'
                                    : $d['speed_mb'] . ' Mb') ?>
                                <?php if ($d['duplex'] !== null && (int) $d['duplex'] === 1): ?>
                                    <span class="muted">full</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="data"><?= $cell($d['mac']) ?></td>
                        <td class="data">
                            <?= $cell($d['driver']) ?>
                            <?php if ($d['driver_version'] !== null): ?>
                                <div class="muted"><?= $e($d['driver_version']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="data"><?= $cell($d['firmware_version']) ?></td>
                        <td class="data"><?= $cell($d['pci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (($devices['hba'] ?? []) !== []): ?>
        <h3 class="section-head">HBA adapteri</h3>
        <div class="panel">
            <table>
                <thead><tr>
                    <th>Adapter</th><th>Vrsta</th><th>Model</th><th>Stanje</th>
                    <th>WWPN / iSCSI ime</th><th>Brzina</th><th>Drajver</th>
                </tr></thead>
                <tbody>
                <?php
                $hbaLabel = [
                    'fibreChannel'   => 'Fibre Channel',
                    'iscsi'          => 'iSCSI',
                    'serialAttached' => 'SAS',
                    'parallelScsi'   => 'Parallel SCSI',
                    'block'          => 'Block',
                ];

                foreach ($devices['hba'] as $d):
                    $online = ($d['status'] ?? '') === 'online';
                ?>
                    <tr>
                        <td class="rail <?= $online ? 'rail-ok' : 'rail-idle' ?>"><?= $e($d['device']) ?></td>
                        <td><?= $e($hbaLabel[$d['hba_type'] ?? ''] ?? ($d['hba_type'] ?? '—')) ?></td>
                        <td><?= $cell($d['model']) ?></td>
                        <td><?= $cell($d['status']) ?></td>
                        <td class="data">
                            <?php if ($d['iscsi_name'] !== null): ?>
                                <?= $e($d['iscsi_name']) ?>
                            <?php elseif ($d['wwpn'] !== null): ?>
                                <?= $e($d['wwpn']) ?>
                                <?php if ($d['wwnn'] !== null): ?>
                                    <div class="muted">node <?= $e($d['wwnn']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($d['wwnn'] !== null): ?>
                                <?= $e($d['wwnn']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="data"><?= $d['speed_mb'] === null ? '—' : $e((int) ((int) $d['speed_mb'] / 1000)) . ' Gb' ?></td>
                        <td class="data"><?= $cell($d['driver']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (($devices['vswitch'] ?? []) !== [] || ($devices['portgroup'] ?? []) !== []): ?>
        <h3 class="section-head">Standardni svičevi i port grupe</h3>
        <div class="panel">
            <table>
                <thead><tr>
                    <th>Svič</th><th>Portova</th><th>Slobodno</th><th>MTU</th>
                </tr></thead>
                <tbody>
                <?php foreach ($devices['vswitch'] ?? [] as $d): ?>
                    <tr>
                        <td class="data"><?= $e($d['device']) ?></td>
                        <td class="data"><?= $cell($d['num_ports']) ?></td>
                        <td class="data"><?= $cell($d['num_ports_available']) ?></td>
                        <td class="data"><?= $cell($d['mtu']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (($devices['portgroup'] ?? []) !== []): ?>
                <table>
                    <thead><tr>
                        <th>Port grupa</th><th>VLAN</th><th>Svič</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($devices['portgroup'] as $d): ?>
                        <tr>
                            <td><?= $e($d['device']) ?></td>
                            <td class="data">
                                <?php if ($d['vlan_id'] === null): ?>
                                    —
                                <?php elseif ((int) $d['vlan_id'] === 0): ?>
                                    <span class="muted">bez VLAN-a</span>
                                <?php elseif ((int) $d['vlan_id'] === 4095): ?>
                                    trunk
                                <?php else: ?>
                                    <?= $e($d['vlan_id']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="data"><?= $cell($d['vswitch_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<h2 class="section-head">
    Mašine na hostu<?= $vms === [] ? '' : ' (' . $e(count($vms)) . ')' ?>
</h2>

<div class="panel">
    <?php if ($vms === []): ?>

        <div class="empty">
            <p>Na ovom hostu nema nijedne mašine.</p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Mašina</th>
                    <th>Stanje</th>
                    <th>vCPU</th>
                    <th>RAM</th>
                    <th>IP adresa</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vms as $vm): ?>
                <tr>
                    <td class="rail <?= $vm['power_state'] === 'POWERED_ON' ? 'rail-ok' : 'rail-idle' ?>">
                        <a href="/vms/<?= $e($vm['id']) ?>"><?= $e($vm['name']) ?></a>
                    </td>
                    <td><?= $e($vmPowerLabel[$vm['power_state']] ?? ($vm['power_state'] ?? '—')) ?></td>
                    <td class="data"><?= $e($vm['cpu_count'] ?? '—') ?></td>
                    <td class="data">
                        <?= $vm['memory_mib'] === null ? '—' : $e(round((int) $vm['memory_mib'] / 1024, 1)) . ' GiB' ?>
                    </td>
                    <td class="data"><?= $e($vm['guest_ip_address'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<h2 class="section-head">Promene<?= $changes === [] ? '' : ' (' . $e(count($changes)) . ')' ?></h2>

<div class="panel">
    <?php if ($changes === []): ?>

        <div class="empty">
            <p>Nijedna promena nije zabeležena otkad se ovaj host prati.</p>
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
