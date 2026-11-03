<?php
// src/Source/Dto/InventoryDto.php
//
// Zajednicki ugovor za sve objekte inventara.
//
// Postoji da bi upis u bazu mogao da bude napisan jednom umesto tri puta:
// skladiste radi sa comparable() i hash(), a ne zna da li je rec o klasteru,
// hostu ili masini.

declare(strict_types=1);

namespace SyncOps\Source\Dto;

interface InventoryDto
{
    /** Identifikator objekta u vCenteru. */
    public function moref(): string;

    /** Sirov odgovor API-ja, onakav kakav je stigao. */
    public function rawPayload(): string;

    /**
     * Vrednosti koje se cuvaju u bazi i porede izmedju run-ova.
     * Kljucevi su imena kolona.
     *
     * @return array<string, scalar|null>
     */
    public function comparable(): array;

    /** SHA-256 nad comparable(). */
    public function hash(): string;
}
