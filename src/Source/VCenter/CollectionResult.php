<?php
// src/Source/VCenter/CollectionResult.php
//
// Sve sto je jedno prikupljanje dalo, pre nego sto je bilo sta upisano u bazu.

declare(strict_types=1);

namespace SyncOps\Source\VCenter;

use SyncOps\Source\Dto\ClusterDto;
use SyncOps\Source\Dto\HostDto;
use SyncOps\Source\Dto\VmDto;

final class CollectionResult
{
    /**
     * @param list<ClusterDto> $clusters
     * @param list<HostDto> $hosts
     * @param list<VmDto> $vms
     * @param list<string> $failures Pojedinacni objekti koji su pukli
     */
    public function __construct(
        public readonly string $flavor,
        public readonly ?string $productVersion,
        public readonly array $clusters,
        public readonly array $hosts,
        public readonly array $vms,
        public readonly array $failures,
        public readonly int $apiCalls,
        public readonly float $durationSeconds,
    ) {
    }

    /**
     * Da li je prikupljanje bilo potpuno. Delimicno znaci da je deo objekata
     * pukao — podatak koji odlucuje da li kasnija obrada sme da veruje
     * kolonama last_seen iz ovog run-a.
     */
    public function isComplete(): bool
    {
        return $this->failures === [];
    }
}
