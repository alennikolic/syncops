<?php
// src/Storage/Repository/HostRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class HostRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_host';
    }

    protected function changeTable(): string
    {
        return 'vc_host_change';
    }

    protected function changeForeignKey(): string
    {
        return 'host_id';
    }
}
