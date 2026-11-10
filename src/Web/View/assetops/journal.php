<?php
// src/Web/View/assetops/journal.php
//
// Dnevnik upisa u AssetOPS.
//
// Odvojen od plana namerno: plan je predlog koji se racuna iznova i zivi samo
// na ekranu, a ovo je zapis o ucinjenom koji ostaje i posle prolaza koji je
// pao na pola. Dva razlicita pitanja, pa i dva ekrana.
//
// Sto se ovde vidi a na planu nije moglo: izmene po POLJU, sa zatecenom i
// novom vrednoscu. Poslato telo kod izmene je ceo procitani CI, pa se iz
// njega ne vidi sta je tacno dirnuto.
//
/** @var callable $e */
/** @var list<\SyncOps\Integration\Integration> $integrations */
/** @var \SyncOps\Integration\Integration $integration */
/** @var list<array<string,mixed>> $entries */
/** @var array{status: array<string,int>, action: array<string,int>, last: ?string, fields: int} $summary */
/** @var array<string,string> $filters */
/** @var int $total */
/** @var int $page */
/** @var int $pages */

$switchTo = '/assetops/journal';

$statusLabel = ['applied' => 'upisano', 'failed' => 'neuspelo', 'skipped' => 'preskočeno'];
$kindLabel   = ['vm' => 'Virtuelna mašina', 'host' => 'Host'];

/** Adresa sa izmenjenim jednim filterom; ostali ostaju kakvi jesu. */
$link = static function (array $promena) use ($filters, $integration): string {
    $q = array_filter(
        array_merge($filters, $promena, ['integration' => (string) $integration->id]),
        static fn (string $v): bool => $v !== ''
    );

    return '/assetops/journal?' . http_build_query($q);
};

/** Vrednost iz izmene u nesto citljivo; null i prazno se vide kao takvi. */
$vrednost = static function (mixed $v) use ($e): string {
    if ($v === null) {
        return '<span class="muted">prazno</span>';
    }

    if (is_bool($v)) {
        return $v ? 'da' : 'ne';
    }

    if (is_array($v)) {
        return '<span class="data">' . $e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</span>';
    }

    $text = (string) $v;

    return $text === '' ? '<span class="muted">prazno</span>' : $e($text);
};

$aktivan = static fn (string $kljuc, string $vrednost): string
    => ($filters[$kljuc] ?? '') === $vrednost ? ' aria-current="true"' : '';
?>

<div class="page-head">
    <div>
        <h1>Dnevnik upisa</h1>
        <p>
            <?= $e($integration->name) ?> &middot;
            <?= $e($summary['status']['applied']) ?> upisano,
            <?= $e($summary['action']['create']) ?> kreiranja,
            <?= $e($summary['action']['update']) ?> izmena
            <?php if ($summary['fields'] > 0): ?>
                &middot; <?= $e($summary['fields']) ?> izmenjenih polja
            <?php endif; ?>
            <?php if ($summary['status']['failed'] > 0): ?>
                &middot; <span class="state-bad"><?= $e($summary['status']['failed']) ?> neuspelo</span>
            <?php endif; ?>
            <?php if ($summary['last'] !== null): ?>
                &middot; poslednji <span class="data"><?= $e(substr($summary['last'], 0, 16)) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php require __DIR__ . '/_switcher.php'; ?>
    </div>
</div>

<?php if ($summary['status']['applied'] + $summary['status']['failed'] + $summary['status']['skipped'] === 0): ?>

    <div class="panel">
        <p class="note-block">
            Još nijedan upis nije pokušan. Dnevnik se puni kada na stranici
            <a href="/assetops/plan">Plan</a> pokreneš sinhronizaciju.
        </p>
    </div>

<?php else: ?>

    <div class="panel">
        <form method="get" action="/assetops/journal" class="filters">
            <input type="hidden" name="integration" value="<?= $e($integration->id) ?>">

            <label>
                Ishod
                <select name="status">
                    <option value="">svi</option>
                    <?php foreach ($statusLabel as $k => $v): ?>
                        <option value="<?= $e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>>
                            <?= $e($v) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Šta
                <select name="action">
                    <option value="">sve</option>
                    <option value="create" <?= ($filters['action'] ?? '') === 'create' ? 'selected' : '' ?>>kreiranje</option>
                    <option value="update" <?= ($filters['action'] ?? '') === 'update' ? 'selected' : '' ?>>izmena</option>
                </select>
            </label>

            <label>
                Izvor
                <select name="kind">
                    <option value="">svi</option>
                    <?php foreach ($kindLabel as $k => $v): ?>
                        <option value="<?= $e($k) ?>" <?= ($filters['kind'] ?? '') === $k ? 'selected' : '' ?>>
                            <?= $e($v) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Ime ili uuid
                <input type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" placeholder="npr. an-ansible">
            </label>

            <button type="submit" class="btn">Primeni</button>

            <?php if (array_filter($filters) !== []): ?>
                <a class="btn btn-quiet" href="/assetops/journal?integration=<?= $e($integration->id) ?>">Poništi</a>
            <?php endif; ?>
        </form>
    </div>

    <h2 class="section-head">
        <?= $e($total) ?> <?= $total === 1 ? 'zapis' : 'zapisa' ?>
        <?php if ($pages > 1): ?>
            <span class="muted">strana <?= $e($page) ?> od <?= $e($pages) ?></span>
        <?php endif; ?>
    </h2>

    <div class="panel">
        <?php if ($entries === []): ?>

            <div class="empty">
                <p>Nijedan zapis ne odgovara filteru.</p>
            </div>

        <?php else: ?>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Kada</th>
                            <th>Objekat</th>
                            <th>Šta</th>
                            <th>Ishod</th>
                            <th>Izmenjeno</th>
                            <th>Poruka</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry):
                        $rail = match ($entry['status']) {
                            'applied' => 'rail-ok',
                            'skipped' => 'rail-idle',
                            default   => 'rail-bad',
                        };

                        $changes = $entry['changes'] === null
                            ? []
                            : (json_decode((string) $entry['changes'], true) ?: []);
                    ?>
                        <tr>
                            <td class="rail <?= $rail ?> data">
                                <?= $e(substr((string) $entry['created_at'], 0, 16)) ?>
                            </td>
                            <td>
                                <?= $e($entry['source_name']) ?>
                                <div class="sub muted">
                                    <?= $e($kindLabel[$entry['source_kind']] ?? $entry['source_kind']) ?>
                                </div>
                                <?php if ($entry['ci_uuid'] !== null): ?>
                                    <div class="sub data muted"><?= $e($entry['ci_uuid']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $entry['action'] === 'create' ? 'kreiranje' : 'izmena' ?></td>
                            <td class="<?= $entry['status'] === 'failed' ? 'state-bad' : '' ?>">
                                <?= $e($statusLabel[$entry['status']] ?? $entry['status']) ?>
                                <?php if ($entry['http_status'] !== null): ?>
                                    <div class="sub data muted">HTTP <?= $e($entry['http_status']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($changes !== []): ?>
                                    <?php // Ovo je racun nad SVEZIM stanjem, pa je tacno ono
                                          // sto je ovaj upis promenio — ne ono sto je plan
                                          // predlagao pre toga. ?>
                                    <table class="inner">
                                        <?php foreach ($changes as $change): ?>
                                            <tr>
                                                <td class="data"><?= $e($change['ref'] ?? '?') ?></td>
                                                <td class="muted"><?= $vrednost($change['from'] ?? null) ?></td>
                                                <td>&rarr;</td>
                                                <td><?= $vrednost($change['to'] ?? null) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php elseif ($entry['action'] === 'create' && ($entry['payload'] ?? null) !== null): ?>
                                    <?php
                                    // Kod kreiranja je sve novo, pa izmene po polju nemaju
                                    // smisla — vredi spisak polja koja su postavljena.
                                    $polja = json_decode((string) $entry['payload'], true);
                                    $polja = is_array($polja) ? array_keys($polja) : [];
                                    ?>
                                    <span class="muted">
                                        <?= $e(implode(', ', array_slice($polja, 0, 6))) ?><?= count($polja) > 6 ? '…' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $e($entry['message'] ?? '—') ?>

                                <?php if (($entry['payload'] ?? null) !== null): ?>
                                    <?php // Kod neuspeha je poslato telo jedino mesto na kom se
                                          // vidi sta je tacno otislo — poruka servera cesto ne
                                          // kaze. Zato stoji i kod uspeha, samo sklopljeno. ?>
                                    <details class="ref-list">
                                        <summary>Poslato telo</summary>
                                        <pre class="raw-full"><?= $e(json_encode(
                                            json_decode((string) $entry['payload'], true),
                                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                        )) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a class="btn btn-quiet" href="<?= $e($link(['page' => (string) ($page - 1)])) ?>">Prethodna</a>
            <?php endif; ?>

            <span class="muted">strana <?= $e($page) ?> od <?= $e($pages) ?></span>

            <?php if ($page < $pages): ?>
                <a class="btn btn-quiet" href="<?= $e($link(['page' => (string) ($page + 1)])) ?>">Sledeća</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<p class="note-block">
    Dnevnik je zapis o učinjenom, ne red čekanja: red nastaje tek kada se poziv pošalje.
    Predlog živi na stranici <a href="/assetops/plan">Plan</a> i računa se iznova, a ovde
    ostaje samo ono što se stvarno dogodilo — i uspeh i neuspeh.
</p>

<p class="note-block">
    Kolona <b>Izmenjeno</b> pokazuje izmene po polju, sa zatečenom i novom vrednošću.
    Račun je nad <b>svežim</b> stanjem pročitanim pred sam upis, pa je to tačno ono što je
    ovaj upis promenio — a ne ono što je plan predlagao pre toga.
</p>
