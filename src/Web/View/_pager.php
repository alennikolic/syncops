<?php
// src/Web/View/_pager.php
//
// Straničenje. Zadržava sve postojeće parametre iz URL-a osim broja strane,
// da filtri prežive prelazak na sledeću stranu.
//
/** @var callable $e */
/** @var array{page: int, pages: int, total: int} $result */

if (($result['pages'] ?? 1) <= 1) {
    return;
}

$link = static function (int $page): string {
    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
};

$page  = (int) $result['page'];
$pages = (int) $result['pages'];

// Prikazuje se najviše sedam brojeva oko trenutne strane.
$from = max(1, $page - 3);
$to   = min($pages, $from + 6);
$from = max(1, $to - 6);
?>

<nav class="pager">
    <?php if ($page > 1): ?>
        <a href="<?= $e($link($page - 1)) ?>" class="btn">Prethodna</a>
    <?php endif; ?>

    <?php if ($from > 1): ?>
        <a href="<?= $e($link(1)) ?>" class="pager-num">1</a>
        <?php if ($from > 2): ?><span class="pager-gap">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $from; $i <= $to; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="pager-num pager-current"><?= $e($i) ?></span>
        <?php else: ?>
            <a href="<?= $e($link($i)) ?>" class="pager-num"><?= $e($i) ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($to < $pages): ?>
        <?php if ($to < $pages - 1): ?><span class="pager-gap">…</span><?php endif; ?>
        <a href="<?= $e($link($pages)) ?>" class="pager-num"><?= $e($pages) ?></a>
    <?php endif; ?>

    <?php if ($page < $pages): ?>
        <a href="<?= $e($link($page + 1)) ?>" class="btn">Sledeća</a>
    <?php endif; ?>
</nav>
