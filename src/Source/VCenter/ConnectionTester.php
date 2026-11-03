<?php
// src/Source/VCenter/ConnectionTester.php
//
// Provera konekcije na vCenter: prijava, prepoznavanje dijalekta i verzije,
// i brzo prebrojavanje inventara da se vidi ima li nalog uopste sta da vidi.
//
// Isti kod koriste i CLI i GUI, pa se ishod i poruke ne razilaze izmedju njih.
//
// Ovo NIJE discovery — nista se ne upisuje u inventory sloj. Jedino sto
// ostavlja traga je integration.last_test_* i prepoznati dijalekt.

declare(strict_types=1);

namespace SyncOps\Source\VCenter;

use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\VCenter\Client\ClientFactory;
use SyncOps\Source\VCenter\Client\ClientInterface;
use Throwable;

final class ConnectionTester
{
    public function __construct(
        private readonly IntegrationRepository $repo,
        private readonly ClientFactory $factory,
    ) {
    }

    public function test(Integration $integration): TestResult
    {
        $client = null;

        try {
            $password = $this->repo->password($integration->id);
            $client   = $this->factory->connect($integration, $password);

            $result = $this->probe($integration, $client);
        } catch (VCenterException $e) {
            $result = TestResult::failed($e->getMessage());
        } catch (Throwable $e) {
            // Neocekivana greska — poruka ide u bazu skracena, pun trag u log.
            error_log('SyncOPS test konekcije: ' . $e->getMessage());

            $result = TestResult::failed('Neocekivana greska: ' . $e->getMessage());
        } finally {
            $client?->logout();
        }

        $this->repo->recordTest(
            id: $integration->id,
            ok: $result->ok,
            message: $result->message,
            detectedFlavor: $result->flavor,
            productVersion: $result->productVersion,
        );

        return $result;
    }

    private function probe(Integration $integration, ClientInterface $client): TestResult
    {
        $counts = [];
        $notes  = [];

        $datacenters = $client->listDatacenters();
        $counts['datacentri'] = count($datacenters);

        $counts['klasteri'] = count($client->listClusters());

        // Hostovi i masine mogu da premase limit liste. To nije greska u
        // konfiguraciji nego podatak koji odredjuje kako ce kolektor raditi,
        // pa se belezi kao napomena a provera se nastavlja.
        $hosts = [];

        try {
            $hosts = $client->listHosts();
            $counts['hostovi'] = count($hosts);
        } catch (VCenterException $e) {
            $counts['hostovi'] = null;
            $notes[] = $e->getMessage();
        }

        // Probna provera SOAP-a na prvom hostu: bolje da se sazna sada nego
        // da se otkrije tek posle prvog prikupljanja.
        if ($hosts !== []) {
            $firstHost = (string) ($hosts[0]['host'] ?? '');

            if ($firstHost !== '' && $client->getHostHardware($firstHost) !== null) {
                $counts['hardver hostova'] = 1;
            } else {
                $counts['hardver hostova'] = null;
                $notes[] = 'Hardverski podaci o hostovima nisu dostupni — proveri da li je '
                         . 'SOAP krajnja tacka /sdk dostupna i da nalog ima pravo citanja.';
            }
        }

        try {
            $counts['masine'] = count($client->listVms());
        } catch (VCenterException $e) {
            $counts['masine'] = null;
            $notes[] = $e->getMessage();
        }

        if ($counts['datacentri'] === 0 && $counts['klasteri'] === 0) {
            $notes[] = 'Nalog je prijavljen ali ne vidi nijedan datacentar ni klaster — '
                     . 'proveri da li je Read-only rola dodeljena na root objektu, sa propagacijom.';
        }

        return TestResult::ok(
            flavor: $client->flavor(),
            productVersion: $client->productVersion(),
            counts: $counts,
            callCount: $client->callCount(),
            notes: $notes,
        );
    }
}
