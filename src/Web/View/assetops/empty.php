<?php
// src/Web/View/assetops/empty.php
//
// Obe AssetOPS stranice kada nijedna integracija nije dodata.
//
/** @var callable $e */
/** @var string $nav */

$isMapping = $nav === 'assetops-mapping';
?>

<div class="page-head">
    <div>
        <h1><?= $isMapping ? 'Mapiranje' : 'Referentni podaci' ?></h1>
        <p>
            <?= $isMapping
                ? 'Čime vCenter objekat postaje CI u AssetOPS-u.'
                : 'Lokacije, statusi, grupe i tipovi pročitani iz AssetOPS-a.' ?>
        </p>
    </div>
</div>

<div class="panel">
    <div class="empty">
        <p>
            Nema nijedne AssetOPS integracije. Dodaj je i proveri vezu, pa se iz nje
            pročitaju lokacije, statusi, visibility grupe, tipovi i definicije atributa —
            tek onda ima iz čega da se mapira.
        </p>
        <a href="/integrations/new/assetops" class="btn btn-primary">Dodaj AssetOPS</a>
    </div>
</div>
