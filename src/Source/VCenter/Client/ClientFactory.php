<?php
// src/Source/VCenter/Client/ClientFactory.php
//
// Sastavlja klijenta za jednu integraciju.
//
// Od uklanjanja REST-a ovde nema sta da se bira: svaki vCenter se cita preko
// vSphere Web Services API-ja (vim25, /sdk). Fabrika ipak ostaje, iz dva
// razloga: transport se u testu podmece preko soapTransport(), a kada stignu
// drugi izvori podataka, ovde ce biti mesto gde se bira njihov klijent.
//
// Zasto je REST uklonjen: vim25 je pun API, REST njegov podskup. REST ne
// vraca VM templejte, nema hardver hostova, trazi poziv po masini za
// instance_uuid i jos jedan za podatke iz gosta, postoji tek od 7.0U2 i ima
// ogranicenje od 4000 objekata. Mereno na istom inventaru od 156 masina:
// REST 255 poziva i 10 sekundi, SOAP 18 poziva i 0,7 sekundi.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SensitiveParameter;
use SyncOps\Integration\Integration;
use SyncOps\Source\VCenter\Client\Soap\SoapTransport;
use SyncOps\Source\VCenter\Client\Soap\Vim25Client;

class ClientFactory
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * Klijent za zadatu integraciju, bez prijave.
     */
    public function make(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): ClientInterface {
        return new Vim25Client(
            $this->soapTransport($integration),
            $integration->username,
            $password,
        );
    }

    /**
     * Prijavljen klijent, spreman za rad.
     */
    public function connect(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): ClientInterface {
        $client = $this->make($integration, $password);
        $client->login();

        return $client;
    }

    /**
     * Transport za jednu integraciju.
     *
     * Izdvojeno u zasebnu metodu da bi se fabrika mogla testirati: test
     * podmetne transport koji vraca snimljen odgovor umesto da zove vCenter.
     * Zato klasa nije final.
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
}
