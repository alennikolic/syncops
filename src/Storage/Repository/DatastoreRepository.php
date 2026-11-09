<?php
// src/Storage/Repository/DatastoreRepository.php

declare(strict_types=1);

namespace SyncOps\Storage\Repository;

use SyncOps\Storage\InventoryRepository;

final class DatastoreRepository extends InventoryRepository
{
    protected function table(): string
    {
        return 'vc_datastore';
    }

    protected function changeTable(): string
    {
        return 'vc_datastore_change';
    }

    protected function changeForeignKey(): string
    {
        return 'datastore_id';
    }
}
