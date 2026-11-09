<?php
// src/Source/VCenter/Collector/DatastoreCollector.php
//
// Prikuplja datastore-ove. Jedan poziv za sve.
//
// Datastore je objekat inventara i ima moref, pa se cita kroz ContainerView,
// isto kao hostovi i klasteri. Pripadnost datacentru se NE trazi posebno:
// jedan isti LUN ume da bude montiran sa vise strana, a i inace se datastore
// gleda po tome ko ga koristi, ne po tome gde u stablu stoji.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\DatastoreDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\DatastoreMapper;

final class DatastoreCollector
{
    public function __construct(
        private readonly DatastoreMapper $mapper = new DatastoreMapper(),
    ) {
    }

    /**
     * @return list<DatastoreDto>
     */
    public function collect(ClientInterface $client): array
    {
        $datastores = [];

        foreach ($client->listDatastores() as $row) {
            // Bez moref-a nema identiteta, pa nema ni reda u bazi.
            if ((string) ($row['datastore'] ?? '') === '') {
                continue;
            }

            $datastores[] = $this->mapper->map($row);
        }

        return $datastores;
    }
}
