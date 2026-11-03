<?php
// src/Source/VCenter/Client/RestClient.php
//
// Dijalekt /api — vSphere 7.0 Update 2 i noviji.
//
// Prijava:  POST /api/session        -> telo odgovora je sam token kao JSON string
// Odgovori: bez omotaca, niz ili objekat direktno
// Filteri:  ?clusters=a&clusters=b   (isti naziv se ponavlja)

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SyncOps\Integration\Integration;

final class RestClient extends AbstractRestClient
{
    public function flavor(): string
    {
        return Integration::FLAVOR_API_V2;
    }

    protected function sessionPath(): string
    {
        return '/api/session';
    }

    protected function vcenterPath(): string
    {
        return '/api/vcenter';
    }

    protected function appliancePath(): string
    {
        return '/api/appliance';
    }

    protected function unwrap(mixed $decoded): mixed
    {
        // Nema omotaca.
        return $decoded;
    }

    protected function filter(array $filters): array
    {
        $pairs = [];

        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                $pairs[] = [$name, (string) $value];
            }
        }

        return $pairs;
    }
}
