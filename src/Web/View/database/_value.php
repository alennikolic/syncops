<?php
// src/Web/View/database/_value.php
//
// Prikaz jedne sirove vrednosti iz baze. Ukljucuje se u stranice pregleda
// baze i definise $rawValue.
//
// Pravilo: nista se ne prevodi i ne formatira. 1 ostaje 1, a ne "da".
// Datum ostaje onakav kakav je u koloni, u UTC-u. Jedina intervencija je
// skracivanje dugackog teksta, i ono se uvek najavi.
//
// NULL i prazan string se prikazuju razlicito. U MySQL-u to nisu iste
// stvari, a razlika je cesto upravo ono sto se trazi kad se otvori ova
// stranica.
//
/** @var callable $e */

/**
 * @param mixed                $value  Vrednost iz reda, ili null ako kolone nema
 * @param array<string, mixed> $column Opis kolone iz SchemaQuery::columns()
 * @param int|null             $limit  Skrati duze od ovoliko znakova
 */
$rawValue = static function (mixed $value, array $column, ?int $limit = null) use ($e): string {
    if (($column['hidden'] ?? false) === true) {
        return '<span class="cell-hidden">‹ ' . $e($column['hidden_reason'] ?? 'skriveno') . ' ›</span>';
    }

    if ($value === null) {
        return '<span class="cell-null">NULL</span>';
    }

    if (($column['binary'] ?? false) === true) {
        return '<span class="cell-hidden">‹ binarno, '
            . $e(number_format(strlen((string) $value), 0, ',', '.'))
            . ' B ›</span>';
    }

    $text = (string) $value;

    if ($text === '') {
        return '<span class="cell-empty">(prazan string)</span>';
    }

    $length = mb_strlen($text);

    if ($limit !== null && $length > $limit) {
        return '<span class="data">' . $e(mb_substr($text, 0, $limit)) . '</span>'
            . '<span class="cell-more">… još ' . $e(number_format($length - $limit, 0, ',', '.'))
            . ' znakova</span>';
    }

    return '<span class="data">' . $e($text) . '</span>';
};
