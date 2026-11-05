<?php
// src/Source/AssetOps/ClientFactory.php
//
// Sastavlja AssetOPS klijenta za jednu integraciju.
//
// Postoji iz istog razloga kao vCenter-ova fabrika: da se u testu moze
// podmetnuti klijent koji vraca snimljen odgovor umesto da zove pravu
// instancu. Zato klasa nije final, a pravljenje klijenta stoji u zasebnoj
// metodi.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use SensitiveParameter;
use SyncOps\Integration\Integration;

class ClientFactory
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {
    }

    public function make(
        Integration $integration,
        #[SensitiveParameter] string $apiKey,
    ): AssetOpsClient {
        return new AssetOpsClient(
            baseUrl: $integration->baseUrl(),
            apiKey: $apiKey,
            verifyPeer: $integration->verifyPeer,
            host: $integration->hostname,
            timeout: $this->timeout,
        );
    }
}
