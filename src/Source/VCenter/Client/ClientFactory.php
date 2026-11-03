<?php
// src/Source/VCenter/Client/ClientFactory.php
//
// Sastavlja klijenta za jednu integraciju.
//
// Ako je dijalekt "auto", pokusava /api pa /rest i vraca onaj koji je uspeo —
// zajedno sa oznakom dijalekta, da pozivalac moze da je upise u bazu i sledeci
// put ide pravo na pravi.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SensitiveParameter;
use SyncOps\Integration\Integration;
use SyncOps\Source\VCenter\Client\Soap\SoapHardwareClient;
use SyncOps\Source\VCenter\Client\Soap\SoapTransport;
use SyncOps\Source\VCenter\VCenterException;

class ClientFactory
{
    public function __construct(
        private readonly int $timeout = 30,
        /** Da li uz REST ide i SOAP, za hardverske podatke o hostovima. */
        private readonly bool $withHardware = true,
    ) {
    }

    /**
     * Klijent za zadati dijalekt, bez prijave.
     */
    public function make(
        Integration $integration,
        #[SensitiveParameter] string $password,
        string $flavor,
    ): ClientInterface {
        $transport = $this->transport($integration);

        $rest = match ($flavor) {
            Integration::FLAVOR_API_V2 => new RestClient($transport, $integration->username, $password),
            Integration::FLAVOR_REST_LEGACY => new RestLegacyClient($transport, $integration->username, $password),
            default => throw new VCenterException("Nepoznat dijalekt API-ja: {$flavor}"),
        };

        if (!$this->withHardware) {
            return $rest;
        }

        return new HardwareAwareClient($rest, new SoapHardwareClient(
            $this->soapTransport($integration),
            $integration->username,
            $password,
        ));
    }

    /**
     * Transport za jednu integraciju.
     *
     * Izdvojeno u zasebnu metodu da bi se fabrika mogla testirati: test
     * podmetne transport ka laznom serveru, a sva logika izbora dijalekta
     * ostaje ista.
     */
    protected function transport(Integration $integration): HttpTransport
    {
        return new HttpTransport(
            baseUrl: $integration->baseUrl(),
            verifyPeer: $integration->verifyPeer,
            host: $integration->hostname,
            timeout: $this->timeout,
        );
    }

    /**
     * SOAP transport za istu integraciju. Izdvojeno zbog testiranja, isto kao
     * i transport().
     */
    protected function soapTransport(Integration $integration): SoapTransport
    {
        return new SoapTransport(
            baseUrl: $integration->baseUrl(),
            verifyPeer: $integration->verifyPeer,
            host: $integration->hostname,
            // Jedan RetrievePropertiesEx nad velikim inventarom ume da potraje.
            timeout: max(120, $this->timeout),
        );
    }

    /**
     * Prijavljen klijent, spreman za rad.
     *
     * Kada je dijalekt "auto", proba se /api pa /rest. Prva uspesna prijava
     * pobedjuje. Pogresni kredencijali prekidaju odmah — nema smisla probati
     * drugi dijalekt sa istom lozinkom.
     */
    public function connect(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): ClientInterface {
        $explicit = $integration->apiFlavor;

        if ($explicit !== Integration::FLAVOR_AUTO) {
            $client = $this->make($integration, $password, $explicit);
            $client->login();

            return $client;
        }

        // Redosled nije slucajan: /api je sadasnjost, /rest je deprecated.
        $candidates = [Integration::FLAVOR_API_V2, Integration::FLAVOR_REST_LEGACY];
        $lastError  = null;

        foreach ($candidates as $flavor) {
            $client = $this->make($integration, $password, $flavor);

            try {
                $client->login();

                return $client;
            } catch (VCenterException $e) {
                // Lose lozinke nema smisla probati dvaput.
                if ($e->httpStatus === 401) {
                    throw $e;
                }

                $lastError = $e;
            }
        }

        throw new VCenterException(
            'Nijedan dijalekt API-ja nije prihvatio prijavu. Poslednja greska: '
            . ($lastError?->getMessage() ?? 'nepoznata'),
            $lastError?->httpStatus,
            $lastError
        );
    }
}
