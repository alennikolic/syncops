<?php
// src/Source/TestResult.php
//
// Ishod provere konekcije, u obliku upotrebljivom i za CLI ispis i za GUI.
//
// Zajednicki za sve tipove integracija: vCenter prijavljuje datacentre i
// masine, AssetOPS CI-jeve i statuse, ali oblik ishoda je isti — da li je
// proslo, sta je nadjeno, koliko poziva i sta treba znati. Zato stoji u
// SyncOps\Source, iznad pojedinacnih izvora.

declare(strict_types=1);

namespace SyncOps\Source;

final class TestResult
{
    /**
     * @param array<string, int|null> $counts null znaci "lista premasuje limit"
     * @param list<string> $notes
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $flavor = null,
        public readonly ?string $productVersion = null,
        public readonly array $counts = [],
        public readonly int $callCount = 0,
        public readonly array $notes = [],
    ) {
    }

    /**
     * @param array<string, int|null> $counts
     * @param list<string> $notes
     */
    public static function ok(
        string $flavor,
        ?string $productVersion,
        array $counts,
        int $callCount,
        array $notes = [],
    ): self {
        return new self(
            ok: true,
            message: self::summarize($counts, $notes),
            flavor: $flavor,
            productVersion: $productVersion,
            counts: $counts,
            callCount: $callCount,
            notes: $notes,
        );
    }

    public static function failed(string $message): self
    {
        return new self(ok: false, message: $message);
    }

    /**
     * Jedan red koji ide u integration.last_test_message.
     *
     * @param array<string, int|null> $counts
     * @param list<string> $notes
     */
    private static function summarize(array $counts, array $notes): string
    {
        $parts = [];

        foreach ($counts as $label => $value) {
            $parts[] = $label . ': ' . ($value === null ? 'preko limita' : $value);
        }

        $summary = implode(', ', $parts);

        if ($notes !== []) {
            $summary .= ' — ' . $notes[0];
        }

        return mb_substr($summary, 0, 500);
    }
}
