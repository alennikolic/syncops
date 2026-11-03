<?php
// src/Storage/Repository/ClusterRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class ClusterRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_cluster';
    }

    protected function changeTable(): string
    {
        return 'vc_cluster_change';
    }

    protected function changeForeignKey(): string
    {
        return 'cluster_id';
    }
}
