<?php
// src/Web/View/database/reset.php
//
// Potvrda pre brisanja prikupljenih podataka.
//
// Potvrda je zasebna stranica a ne iskacuci prozor: pokazuje tacne brojeve
// redova koji odlaze, radi i bez JavaScript-a, i ne moze da se preskoci
// slucajnim drugim klikom.
//
/** @var callable $e */
/** @var array<string, int> $clearable */
/** @var list<string> $preserved */
/** @var bool $busy */
/** @var string $csrf */

$total = array_sum($clearable);
?>

<div class="page-head">
    <div>
        <h1>Brisanje prikupljenih podataka</h1>
        <p>Integracije i njihove lozinke ostaju.</p>
    </div>
    <a href="/db" class="btn">Odustani</a>
</div>

<?php if ($busy): ?>
    <div class="flash flash-error">
        Prikupljanje je u toku ili čeka u redu. Brisanje bi workeru izmaklo tabele
        ispod ruku, pa se ne izvršava dok se posao ne završi.
        Pogledaj <a href="/runs">Prikupljanja</a>.
    </div>
<?php endif; ?>

<div class="detail-grid">

    <section class="panel">
        <h2>Briše se</h2>
        <?php if ($total === 0): ?>
            <p class="hint">Nema šta da se briše — sve tabele su već prazne.</p>
        <?php else: ?>
            <dl>
                <?php foreach ($clearable as $table => $rows): ?>
                    <dt class="data"><?= $e($table) ?></dt>
                    <dd>
                        <?= $rows === 0
                            ? '<span class="muted">prazna</span>'
                            : '<span class="data">' . $e(number_format($rows, 0, ',', '.')) . ' redova</span>' ?>
                    </dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Ostaje</h2>
        <dl>
            <?php foreach ($preserved as $table): ?>
                <dt class="data"><?= $e($table) ?></dt>
                <dd><span class="muted">netaknuto</span></dd>
            <?php endforeach; ?>
        </dl>
        <p class="hint">
            Podešeni vCenteri, korisnička imena i šifrovane lozinke ostaju kakvi jesu.
            Briše se i podatak o poslednjem testu veze, jer bez istorije prikupljanja
            više nema uz šta da stoji.
        </p>
    </section>

</div>

<div class="panel">
    <p style="margin-top:0">
        Ukupno <b><?= $e(number_format($total, 0, ',', '.')) ?></b> redova.
        Brojači se vraćaju na početak, pa sledeće prikupljanje opet nosi broj 1.
        <b>Ovo se ne može poništiti.</b>
    </p>

    <form method="post" action="/db/reset" class="form-actions" style="margin:0">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-danger" <?= $busy || $total === 0 ? 'disabled' : '' ?>>
            Obriši prikupljene podatke
        </button>
        <a href="/db" class="btn">Odustani</a>
    </form>
</div>
