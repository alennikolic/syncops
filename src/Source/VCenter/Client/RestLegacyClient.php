<?php
// src/Source/VCenter/Client/RestLegacyClient.php
//
// Dijalekt /rest — vSphere 6.7 do 7.0.
//
// Prijava:  POST /rest/com/vmware/cis/session  -> {"value": "<token>"}
// Odgovori: sve je umotano u {"value": ...}
// Filteri:  ?filter.clusters.1=a&filter.clusters.2=b  (indeksirano od 1)
//
// Ovaj namespace je deprecated ali i dalje prisutan radi kompatibilnosti.
// Jednog dana ce otpasti, i tada ova klasa prestaje da bude potrebna.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SyncOps\Integration\Integration;

final class RestLegacyClient extends AbstractRestClient
{
    public function flavor(): string
    {
        return Integration::FLAVOR_REST_LEGACY;
    }

    protected function sessionPath(): string
    {
        return '/rest/com/vmware/cis/session';
    }

    protected function vcenterPath(): string
    {
        return '/rest/vcenter';
    }

    protected function appliancePath(): string
    {
        return '/rest/appliance';
    }

    protected function unwrap(mixed $decoded): mixed
    {
        if (is_array($decoded) && array_key_exists('value', $decoded)) {
            return $decoded['value'];
        }

        return $decoded;
    }

    protected function filter(array $filters): array
    {
        $pairs = [];

        foreach ($filters as $name => $values) {
            $index = 1;

            foreach ($values as $value) {
                $pairs[] = ['filter.' . $name . '.' . $index, (string) $value];
                $index++;
            }
        }

        return $pairs;
    }
}
