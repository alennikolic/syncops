<?php
// src/Web/View/database/index.php
//
// Spisak tabela u bazi, sa stvarnim brojem redova.
//
/** @var callable $e */
/** @var string $database */
/** @var list<array<string,mixed>> $tables */

$size = static function (mixed $bytes) use ($e): string {
    $bytes = (int) $bytes;

    if ($bytes <= 0) {
        return '<span class="muted">—</span>';
    }

    $units = ['B', 'KiB', 'MiB', 'GiB'];
    $i     = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return '<span class="data">' . $e(round($bytes, $i === 0 ? 0 : 1)) . ' ' . $units[$i] . '</span>';
};

$totalRows = array_sum(array_column($tables, 'rows'));
?>

<div class="page-head">
    <div>
        <h1>Baza</h1>
        <p>
            <?= $e(count($tables)) ?> tabela &middot;
            <?= $e(number_format($totalRows, 0, ',', '.')) ?> redova &middot;
            <span class="data"><?= $e($database) ?></span>
        </p>
    </div>
</div>

<div class="panel">
    <?php if ($tables === []): ?>

        <div class="empty">
            <p>U bazi nema nijedne tabele. Pokreni migracije:
                <code class="data">docker compose exec app php bin/syncops migrate</code>
            </p>
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Tabela</th>
                    <th>Redova</th>
                    <th>Veličina</th>
                    <th>Namena</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tables as $table): ?>
                <tr>
                    <td class="rail <?= (int) $table['rows'] > 0 ? 'rail-ok' : 'rail-idle' ?>">
                        <a href="/db/<?= $e(rawurlencode((string) $table['name'])) ?>"
                           class="data"><?= $e($table['name']) ?></a>
                    </td>
                    <td class="data"><?= $e(number_format((int) $table['rows'], 0, ',', '.')) ?></td>
                    <td><?= $size((int) $table['data_bytes'] + (int) $table['index_bytes']) ?></td>
                    <td>
                        <?= $table['comment'] === ''
                            ? '<span class="muted">—</span>'
                            : $e($table['comment']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<p class="note-block">
    Ova stranica čita samo. Nema unosa SQL-a, nema izmena i nema brisanja —
    jedino što radi je <code class="data">SELECT</code> nad tabelom koju si otvorio.
    Šifrovana lozinka vCenter naloga se ne čita ni u prikaz.
</p>
