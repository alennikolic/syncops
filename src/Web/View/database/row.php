<?php
// src/Web/View/database/row.php
//
// Jedan red, sve kolone, pune vrednosti bez skracivanja.
//
// Ovo je kraj puta za "hocu da vidim sta je tacno stiglo": payload iz
// raw_object se ovde cita znak po znak, onako kako ga je API poslao.
//
/** @var callable $e */
/** @var string $table */
/** @var string $id */
/** @var string|null $primaryKey */
/** @var list<array<string,mixed>> $columns */
/** @var array<string,mixed> $row */

require __DIR__ . '/_value.php';
/** @var callable $rawValue */

/** Da li vrednost izgleda kao JSON i moze da se prikaze uredjeno. */
$asJson = static function (mixed $value): ?string {
    if (!is_string($value) || $value === '') {
        return null;
    }

    $first = $value[0];

    if ($first !== '{' && $first !== '[') {
        return null;
    }

    $decoded = json_decode($value, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    $pretty = json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $pretty === false ? null : $pretty;
};
?>

<div class="page-head">
    <div>
        <h1 class="data"><?= $e($table) ?> #<?= $e($id) ?></h1>
        <p>Pun sadržaj reda, bez skraćivanja</p>
    </div>
    <a href="/db/<?= $e(rawurlencode($table)) ?>" class="btn">Nazad na tabelu</a>
</div>

<div class="panel">
    <table class="row-table">
        <tbody>
        <?php foreach ($columns as $column):
            $name  = (string) $column['name'];
            $value = $row[$name] ?? null;
            // Sakrivena i binarna kolona nikad ne idu u <pre>: prva nema
            // sadrzaj, a druga bi u njega izlila bajtove.
            $plain = $column['hidden'] !== true && $column['binary'] !== true;
            $json  = $plain ? $asJson($value) : null;
            $long  = $plain && is_string($value) && mb_strlen($value) > 120;
        ?>
            <tr>
                <th>
                    <span class="data"><?= $e($name) ?></span>
                    <span class="sub muted"><?= $e($column['type']) ?></span>
                    <?php if ($name === $primaryKey): ?>
                        <span class="sub muted">primarni ključ</span>
                    <?php endif; ?>
                </th>
                <td>
                    <?php if ($long || $json !== null): ?>
                        <pre class="raw-full"><?= $e((string) $value) ?></pre>

                        <?php if ($json !== null): ?>
                            <details class="json-view">
                                <summary>Pročitano kao JSON</summary>
                                <pre class="raw-full"><?= $e($json) ?></pre>
                            </details>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= $rawValue($value, $column, null) ?>
                    <?php endif; ?>

                    <?php if ($column['comment'] !== ''): ?>
                        <p class="hint"><?= $e($column['comment']) ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="note-block">
    Gornji blok je sadržaj kolone onakav kakav je u bazi. „Pročitano kao JSON"
    je isti taj tekst samo uvučen radi čitanja — ništa se ne dodaje i ne izostavlja.
</p>
