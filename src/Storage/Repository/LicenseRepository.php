<?php
// src/Storage/Repository/LicenseRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class LicenseRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_license';
    }

    protected function changeTable(): string
    {
        return 'vc_license_change';
    }

    protected function changeForeignKey(): string
    {
        return 'license_id';
    }
}
