<?php
// src/Storage/Repository/VmRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class VmRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_vm';
    }

    protected function changeTable(): string
    {
        return 'vc_vm_change';
    }

    protected function changeForeignKey(): string
    {
        return 'vm_id';
    }
}
