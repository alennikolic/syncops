<?php
// src/Web/CsvExport.php
//
// Slanje CSV-a pregledacu, u obliku koji Excel otvara bez muke.
//
// Tri stvari koje CSV za Excel traze, a lako se zaborave:
//
//   1. BOM na pocetku. Bez njega Excel cita UTF-8 kao windows-1252, pa od
//      "mašina" postane "maÅ¡ina".
//   2. Red "sep=;" kao prva linija. Excel razdvajac bira prema lokalizaciji
//      sistema, pa bi bez ovoga na nekim masinama ceo red zavrsio u jednoj
//      koloni. LibreOffice takodje postuje ovu liniju.
//   3. Zastita od formula. Celija koja pocinje sa = + @ se u Excel-u izvrsava
//      kao formula; ime masine koje pocne tim znakom bi postalo racunska
//      operacija ili, gore, poziv spoljne komande.

declare(strict_types=1);

namespace SyncOps\Web;

final class CsvExport
{
    private const DELIMITER = ';';

    /** Znaci od kojih Excel pocinje da tumaci celiju kao formulu. */
    private const DANGEROUS_PREFIXES = ['=', '+', '@', "\t", "\r"];

    /**
     * @param list<string> $header
     * @param iterable<array<int|string, mixed>> $rows Redovi, vec svedeni na vrednosti
     */
    public function send(string $filename, array $header, iterable $rows): void
    {
        $this->headers($filename);

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        // 1. BOM
        fwrite($out, "\xEF\xBB\xBF");

        // 2. Razdvajac, izricito
        fwrite($out, 'sep=' . self::DELIMITER . "\r\n");

        fputcsv($out, $header, self::DELIMITER, '"', '');

        $flushEvery = 200;
        $written    = 0;

        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'safe'], array_values($row)), self::DELIMITER, '"', '');

            if (++$written % $flushEvery === 0) {
                flush();
            }
        }

        fclose($out);
    }

    private function headers(string $filename): void
    {
        // Ako je nesto vec poslato, zaglavlja se ne mogu promeniti i fajl bi
        // stigao pokvaren; bolje tiho odustati nego poslati smece.
        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        // Prazni medjubaferi, da se izvoz salje u toku a ne tek na kraju.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Onemogucava da Excel protumaci vrednost kao formulu.
     *
     * Apostrof ispred je standardno resenje: Excel ga ne prikazuje u celiji
     * nego ga tumaci kao "ovo je tekst".
     */
    private static function safe(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], self::DANGEROUS_PREFIXES, true)) {
            return "'" . $text;
        }

        return $text;
    }
}
