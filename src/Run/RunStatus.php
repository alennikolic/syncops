<?php
// src/Run/RunStatus.php
//
// Vrednosti statusa, da se ne kucaju kao golim stringovima po kodu.
// Moraju da odgovaraju ENUM vrednostima iz migracije 0002.

declare(strict_types=1);

namespace SyncOps\Run;

final class RunStatus
{
    // discovery_run.status
    public const QUEUED  = 'queued';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const FAILED  = 'failed';

    // discovery_run.trigger_source
    public const TRIGGER_GUI      = 'gui';
    public const TRIGGER_CLI      = 'cli';
    public const TRIGGER_SCHEDULE = 'schedule';

    // discovery_run.{clusters,hosts,vms}_status
    public const TYPE_PENDING = 'pending';
    public const TYPE_OK      = 'ok';
    public const TYPE_FAILED  = 'failed';

    /** Da li je run zavrsen, bez obzira na ishod. */
    public static function isFinished(string $status): bool
    {
        return in_array($status, [self::SUCCESS, self::PARTIAL, self::FAILED], true);
    }

    /**
     * Da li kasnija obrada sme da veruje kolonama last_seen iz ovog run-a.
     *
     * Samo potpun run daje pravo na zakljucak "objekta nema vise". Posle
     * delimicnog ili neuspelog run-a, nedostatak objekta moze da znaci i da
     * prikupljanje nije stiglo do njega.
     */
    public static function isTrustworthy(string $status): bool
    {
        return $status === self::SUCCESS;
    }
}
