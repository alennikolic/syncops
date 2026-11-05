<?php
// src/Web/View/inventory/_raw.php
//
// Sirov odgovor API-ja za objekat koji se gleda. Ukljucuje se na kraj
// stranica detalja masine i hosta.
//
// Postoji da bi se, kad se prikaz i stvarnost ne slazu, moglo proveriti sta
// je vCenter zaista poslao — pre nego sto se krene da se trazi greska u
// maperu ili u prikazu.
//
/** @var callable $e */
/** @var list<array<string,mixed>> $raw */
/** @var string|null $rawLatest */

$pretty = null;

if ($rawLatest !== null && $rawLatest !== '') {
    $decoded = json_decode($rawLatest, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $encoded = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $pretty = $encoded === false ? null : $encoded;
    }
}
?>

<h2 class="section-head">Sirov odgovor<?= $raw === [] ? '' : ' (' . $e(count($raw)) . ' prikupljanja)' ?></h2>

<div class="panel">
    <?php if ($raw === []): ?>

        <div class="empty">
            <p>
                Za ovaj objekat nema sačuvanog sirovog odgovora. Zapisi u
                <span class="data">raw_object</span> nastaju tek pri prikupljanju —
                ako je red u inventaru stariji od te tabele, ovde neće biti ničega.
            </p>
        </div>

    <?php else: ?>

        <?php if ($pretty !== null): ?>
            <pre class="raw-full"><?= $e($pretty) ?></pre>
            <p class="hint">
                Poslednje prikupljanje, uvučeno radi čitanja. Tekst je nepromenjen —
                doslovan zapis je u
                <a href="/db/raw_object/<?= $e($raw[0]['id']) ?>">celom redu</a>.
            </p>
        <?php elseif ($rawLatest !== null): ?>
            <pre class="raw-full"><?= $e($rawLatest) ?></pre>
            <p class="hint">
                Odgovor nije valjan JSON, pa se prikazuje kako je stigao. To samo po
                sebi nije greška — <span class="data">raw_object</span> čuva tačno ono
                što je API vratio, bez provere.
            </p>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Prikupljeno (UTC)</th>
                    <th>Run</th>
                    <th>Hash</th>
                    <th>Veličina</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($raw as $index => $entry): ?>
                <tr>
                    <td class="rail <?= $index === 0 ? 'rail-ok' : 'rail-idle' ?> data">
                        <?= $e($entry['collected_at']) ?>
                    </td>
                    <td class="data">#<?= $e($entry['run_id']) ?></td>
                    <td class="data hash"><?= $e(substr((string) $entry['payload_hash'], 0, 12)) ?></td>
                    <td class="data"><?= $e(number_format((int) $entry['payload_length'], 0, ',', '.')) ?> znakova</td>
                    <td>
                        <a href="/db/raw_object/<?= $e($entry['id']) ?>">Pogledaj</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="hint">
            Isti hash u dva uzastopna reda znači da se odgovor nije promenio između
            prikupljanja — tada inventar nije ni dirnut.
        </p>

    <?php endif; ?>
</div>
