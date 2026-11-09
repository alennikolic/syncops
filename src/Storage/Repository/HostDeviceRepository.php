<?php
// src/Storage/Repository/HostDeviceRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class HostDeviceRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_host_device';
    }

    protected function changeTable(): string
    {
        return 'vc_host_device_change';
    }

    protected function changeForeignKey(): string
    {
        return 'device_id';
    }
}
