<?php
// src/Web/View/database/table.php
//
// Jedna tabela: definicija kolona, indeksi, strani kljucevi i sirovi redovi.
//
/** @var callable $e */
/** @var string $table */
/** @var list<array<string,mixed>> $columns */
/** @var list<array{name:string,unique:bool,type:string,columns:list<string>}> $indexes */
/** @var list<array<string,mixed>> $foreignKeys */
/** @var string|null $primaryKey */
/** @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $result */

require __DIR__ . '/_value.php';
/** @var callable $rawValue */

$visible = array_values(array_filter($columns, static fn (array $c): bool => $c['hidden'] !== true));
$hidden  = array_values(array_filter($columns, static fn (array $c): bool => $c['hidden'] === true));
?>

<div class="page-head">
    <div>
        <h1 class="data"><?= $e($table) ?></h1>
        <p>
            <?= $e(count($columns)) ?> kolona &middot;
            <?= $e(number_format((int) $result['total'], 0, ',', '.')) ?> redova
            <?php if ($primaryKey !== null): ?>
                &middot; primarni ključ <span class="data"><?= $e($primaryKey) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a href="/db" class="btn">Nazad na spisak</a>
</div>

<h2 class="section-head">Kolone</h2>

<div class="panel table-scroll">
    <table>
        <thead>
            <tr>
                <th>Kolona</th>
                <th>Tip</th>
                <th>NULL</th>
                <th>Ključ</th>
                <th>Podrazumevano</th>
                <th>Dodatno</th>
                <th>Komentar</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($columns as $column): ?>
            <tr>
                <td class="rail <?= $column['key_type'] === 'PRI' ? 'rail-ok' : 'rail-idle' ?>">
                    <span class="data"><?= $e($column['name']) ?></span>
                </td>
                <td class="data"><?= $e($column['type']) ?></td>
                <td>
                    <?= $column['nullable'] === 'YES'
                        ? '<span class="muted">da</span>'
                        : '<span class="data">NE</span>' ?>
                </td>
                <td>
                    <?= $column['key_type'] === ''
                        ? '<span class="muted">—</span>'
                        : '<span class="data">' . $e($column['key_type']) . '</span>' ?>
                </td>
                <td>
                    <?= $column['default_value'] === null
                        ? '<span class="cell-null">NULL</span>'
                        : '<span class="data">' . $e($column['default_value']) . '</span>' ?>
                </td>
                <td>
                    <?= $column['extra'] === ''
                        ? '<span class="muted">—</span>'
                        : '<span class="data">' . $e($column['extra']) . '</span>' ?>
                </td>
                <td class="col-comment">
                    <?= $column['comment'] === ''
                        ? '<span class="muted">—</span>'
                        : $e($column['comment']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($indexes !== [] || $foreignKeys !== []): ?>
    <div class="detail-grid">

        <?php if ($indexes !== []): ?>
            <section class="panel">
                <h2>Indeksi</h2>
                <dl>
                    <?php foreach ($indexes as $index): ?>
                        <dt class="data"><?= $e($index['name']) ?></dt>
                        <dd>
                            <span class="data"><?= $e(implode(', ', $index['columns'])) ?></span>
                            <?php if ($index['unique']): ?>
                                <span class="tag">jedinstven</span>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php if ($foreignKeys !== []): ?>
            <section class="panel">
                <h2>Strani ključevi</h2>
                <dl>
                    <?php foreach ($foreignKeys as $fk): ?>
                        <dt class="data"><?= $e($fk['column_name']) ?></dt>
                        <dd>
                            →
                            <a href="/db/<?= $e(rawurlencode((string) $fk['ref_table'])) ?>" class="data"><?= $e($fk['ref_table']) ?></a><span
                                class="data">.<?= $e($fk['ref_column']) ?></span>
                            <span class="sub muted">
                                ON DELETE <?= $e($fk['on_delete']) ?>
                            </span>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

    </div>
<?php endif; ?>

<h2 class="section-head">
    Redovi<?= $result['total'] === 0 ? '' : ' (' . $e(number_format((int) $result['total'], 0, ',', '.')) . ')' ?>
</h2>

<div class="panel table-scroll">
    <?php if ($result['rows'] === []): ?>

        <div class="empty">
            <p>Tabela je prazna.</p>
        </div>

    <?php else: ?>

        <table class="raw-table">
            <thead>
                <tr>
                    <?php if ($primaryKey !== null): ?>
                        <th></th>
                    <?php endif; ?>
                    <?php foreach ($columns as $column): ?>
                        <th class="data"><?= $e($column['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <?php if ($primaryKey !== null): ?>
                        <td class="row-open">
                            <a href="/db/<?= $e(rawurlencode($table)) ?>/<?= $e(rawurlencode((string) $row[$primaryKey])) ?>"
                               title="Ceo red">›</a>
                        </td>
                    <?php endif; ?>

                    <?php foreach ($columns as $column): ?>
                        <td><?= $rawValue($row[$column['name']] ?? null, $column, 80) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<?php if ($result['rows'] !== []): ?>
    <p class="note-block">
        Vrednosti su onakve kakve stoje u koloni — bez prevoda, zaokruživanja i
        pretvaranja jedinica. Vreme je u UTC-u, kako je i upisano.
        Duži tekst je skraćen samo u ovom prikazu; ceo sadržaj je iza strelice
        na početku reda.
        <?php if ($hidden !== []): ?>
            Kolona
            <span class="data"><?= $e(implode(', ', array_column($hidden, 'name'))) ?></span>
            se ne čita iz baze.
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../_pager.php'; ?>
