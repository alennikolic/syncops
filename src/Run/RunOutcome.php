<?php
// src/Run/RunOutcome.php
//
// Ishod jednog discovery posla: sta je prikupljeno i sta je od toga stvarno
// zavrsilo u bazi.

declare(strict_types=1);

namespace SyncOps\Run;

use SyncOps\Source\VCenter\CollectionResult;

final class RunOutcome
{
    /**
     * @param array<string, array{found: int, created: int, changed: int, unchanged: int}> $stats
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $status,
        public readonly CollectionResult $collection,
        public readonly array $stats,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === RunStatus::SUCCESS;
    }

    /** Jedan red za flash poruku u GUI-ju. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->stats as $type => $s) {
            $parts[] = sprintf(
                '%s: %d (novih %d, izmenjenih %d)',
                $type,
                $s['found'],
                $s['created'],
                $s['changed']
            );
        }

        return implode(', ', $parts);
    }
}
