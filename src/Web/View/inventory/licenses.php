<?php
// src/Web/View/inventory/licenses.php
//
// Licence prikupljene sa vCentera.
//
// Nema filtera ni stranicenja, kao ni kod klastera: licenci je po vCenteru
// desetak, ne stotine.
//
// Redosled dolazi iz upita — ono sto pre istice je gore. Ekran ima tri stvari
// koje se gledaju: sta istice, sta je popunjeno do vrha i koji kljuc je u
// pitanju.
//
/** @var callable $e */
/** @var list<array<string,mixed>> $licenses */
/** @var array<int, int> $lastRuns */
/** @var string $nowUtc */

$istice = 0;
$isteklo = 0;

foreach ($licenses as $license) {
    if ($license['expires_at'] === null) {
        continue;
    }

    if ((string) $license['expires_at'] < $nowUtc) {
        $isteklo++;
    } else {
        $istice++;
    }
}
?>

<div class="page-head">
    <div>
        <h1>Licence</h1>
        <p>
            <?= $e(count($licenses)) ?> licenci
            <?php if ($isteklo > 0): ?>
                &middot; <span class="state-bad"><?= $e($isteklo) ?> isteklo</span>
            <?php endif; ?>
            <?php if ($istice > 0): ?>
                &middot; <?= $e($istice) ?> sa rokom
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="panel">
    <?php if ($licenses === []): ?>

        <div class="empty">
            <p>
                Nema licenci. Pokreni prikupljanje da bi se tabela popunila.
                Ako i posle toga ostane prazna, pogledaj napomenu uz poslednje
                prikupljanje — čitanje licenci traži pravo
                <span class="data">Global.Licenses</span>, koje namenski nalog
                često nema.
            </p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Licenca</th>
                    <th>Proizvod</th>
                    <th>Iskorišćeno</th>
                    <th>Jedinica</th>
                    <th>Ističe</th>
                    <th>Ključ</th>
                    <th>Poslednji put viđeno</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($licenses as $license):
                $lastRun = $lastRuns[(int) $license['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $license['last_run_id'] < $lastRun;

                $rok      = $license['expires_at'] === null ? null : (string) $license['expires_at'];
                $istekla  = $rok !== null && $rok < $nowUtc;

                // total = 0 znaci neograniceno, ne "nema mesta".
                $ukupno      = $license['total'] === null ? null : (int) $license['total'];
                $zauzeto     = $license['used'] === null ? null : (int) $license['used'];
                $neograniceno = $ukupno === 0;
                $popunjena   = !$neograniceno && $ukupno !== null && $zauzeto !== null && $zauzeto >= $ukupno;
            ?>
                <tr>
                    <td class="rail <?= $istekla || $missing ? 'rail-bad' : ($popunjena ? 'rail-warn' : 'rail-ok') ?>">
                        <?= $e($license['name']) ?>
                        <div class="muted"><?= $e($license['integration_name']) ?></div>
                    </td>
                    <td>
                        <?= $e($license['product_name'] ?? '—') ?>
                        <?php if ($license['product_version'] !== null): ?>
                            <div class="muted data"><?= $e($license['product_version']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?php if ($zauzeto === null): ?>
                            <span class="muted">—</span>
                        <?php elseif ($neograniceno): ?>
                            <?= $e($zauzeto) ?> <span class="muted">/ neograničeno</span>
                        <?php else: ?>
                            <span class="<?= $popunjena ? 'state-warn' : '' ?>">
                                <?= $e($zauzeto) ?> / <?= $e($ukupno) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($license['cost_unit'] ?? '—') ?></td>
                    <td>
                        <?php if ($rok === null): ?>
                            <span class="muted">bez roka</span>
                        <?php else: ?>
                            <span class="data"><?= $e(substr($rok, 0, 10)) ?></span>
                            <?php if ($istekla): ?>
                                <div class="state-bad">isteklo</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($license['moref']) ?></td>
                    <td>
                        <span class="data"><?= $e($license['last_seen']) ?></span>
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
