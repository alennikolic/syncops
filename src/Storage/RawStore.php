<?php
// src/Storage/RawStore.php
//
// Upis sirovog JSON-a u raw_object, jedan red po objektu po run-u.
//
// Upisuje se u grupama od 50 redova jednim INSERT-om: 153 masine kao 153
// zasebna upita su 153 odlaska do baze bez razloga.
//
// UNIQUE (run_id, object_type, moref) stiti od duplikata kada se lista masina
// particionise po klasteru pa se ista masina pojavi u dve particije — zato
// INSERT IGNORE a ne obican INSERT.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;
use SyncOps\Source\Dto\InventoryDto;

final class RawStore
{
    private const BATCH = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<InventoryDto> $objects
     * @return int Broj upisanih redova
     */
    public function store(int $runId, int $integrationId, string $objectType, array $objects): int
    {
        if ($objects === []) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($objects, self::BATCH) as $chunk) {
            $placeholders = implode(', ', array_fill(
                0,
                count($chunk),
                '(?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            ));

            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO raw_object
                    (run_id, integration_id, object_type, moref, payload, payload_hash, collected_at)
                 VALUES ' . $placeholders
            );

            $params = [];

            foreach ($chunk as $object) {
                $params[] = $runId;
                $params[] = $integrationId;
                $params[] = $objectType;
                $params[] = $object->moref();
                $params[] = $object->rawPayload();
                $params[] = $object->hash();
            }

            $stmt->execute($params);
            $written += $stmt->rowCount();
        }

        return $written;
    }
}
