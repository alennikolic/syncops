<?php
// src/Source/VCenter/Collector/HostDeviceCollector.php
//
// Prikuplja uredjaje hostova: mrezne kartice, VMkernel adaptere, standardne
// sviceve, port grupe i HBA.
//
// ZASEBAN PROLAZ, a ne dopuna postojeceg citanja hostova. Mereno na stvarnom
// odgovoru: za pet hostova odgovor raste sa 1,1 KB na 291 KB, dakle 255 puta.
// HostCollector cita hostove vise puta — punu listu plus jednom po datacentru
// — pa bi se taj trosak mnozio brojem datacentara. Ovako je jedan prolaz,
// tri poziva, i 291 KB tacno jednom po prikupljanju.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\HostDeviceDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\HostDeviceMapper;

final class HostDeviceCollector
{
    public function __construct(
        private readonly HostDeviceMapper $mapper = new HostDeviceMapper(),
    ) {
    }

    /**
     * @return list<HostDeviceDto>
     */
    public function collect(ClientInterface $client): array
    {
        $devices = [];

        foreach ($client->listHostDevices() as $row) {
            // Bez hosta, vrste ili imena nema kljuca, pa nema ni reda u bazi.
            if ((string) ($row['host_moref'] ?? '') === ''
                || (string) ($row['kind'] ?? '') === ''
                || (string) ($row['device'] ?? '') === ''
            ) {
                continue;
            }

            $devices[] = $this->mapper->map($row);
        }

        return $devices;
    }
}
