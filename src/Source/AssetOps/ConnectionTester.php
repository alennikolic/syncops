<?php
// src/Source/AssetOps/ConnectionTester.php
//
// Provera konekcije na AssetOPS.
//
// Ovo NIJE citanje inventara — nista se ne upisuje. Cilj je da se pre prvog
// pravog posla odgovori na tri pitanja:
//
//   1. Da li kljuc uopste prolazi i da li je poslat kao kljuc, a ne kao token.
//   2. Da li kljuc VIDI ono sto treba da sinhronizuje. API kljuc nosi prava
//      korisnika za kog je izdat, a visibility grupe ogranicavaju vidljivost:
//      zapis koji kljuc ne vidi izgleda isto kao zapis koji ne postoji. Za
//      evidenciju je to najopasnija vrsta greske, pa se broje zapisi.
//   3. Da li postoje referentni podaci bez kojih upis ne moze — statusi i
//      tipovi CI-ja, jer se veze salju kao slugovi.
//
// Pet poziva, bez obzira na velicinu inventara: po jedan sa items-per-page=1
// za svaku rutu, jer nas zanima total_items a ne sadrzaj.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Source\SourceTester;
use SyncOps\Source\TestResult;
use Throwable;

final class ConnectionTester implements SourceTester
{
    public function __construct(
        private readonly IntegrationRepository $repo,
        private readonly ClientFactory $factory = new ClientFactory(),
    ) {
    }

    public function test(Integration $integration): TestResult
    {
        try {
            $client = $this->factory->make($integration, $this->repo->password($integration->id));
            $result = $this->probe($client);
        } catch (AssetOpsException $e) {
            $result = TestResult::failed($e->getMessage());
        } catch (Throwable $e) {
            // Neocekivana greska — poruka ide u bazu skracena, pun trag u log.
            error_log('SyncOPS AssetOPS test: ' . $e->getMessage());

            $result = TestResult::failed('Neocekivana greska: ' . $e->getMessage());
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

    private function probe(AssetOpsClient $client): TestResult
    {
        $counts = [];
        $notes  = [];

        // Prvi poziv je i provera prijave: ako kljuc ne valja, ovde puca.
        $counts['virtuelne masine'] = $client->countOf('/api/virtual-assets/');
        $counts['oprema']           = $client->countOf('/api/assets/');
        $counts['statusi']          = $client->countOf('/api/statuses/');
        $counts['tipovi masina']    = $client->countOf('/api/virtual-asset-types/');

        // Lokacije se broje posebno: odgovor je stablo, pa total_items broji
        // samo korenske lokacije. Broj ispod je zato "korena", ne "lokacija".
        $counts['korena lokacija'] = $client->countOf('/api/locations/');

        if ($counts['virtuelne masine'] === 0 && $counts['oprema'] === 0) {
            $notes[] = 'Kljuc radi ali ne vidi nijedan CI. Proveri prava korisnika za kog je '
                     . 'izdat i njegove visibility grupe — prazna lista i nedostatak prava '
                     . 'izgledaju isto.';
        }

        if ($counts['statusi'] === 0) {
            $notes[] = 'Nema nijednog statusa. Upis ce pasti, jer se status salje kao slug.';
        }

        $notes[] = 'Broj lokacija je veci od prikazanog: AssetOPS za lokacije broji samo korene stabla.';

        return TestResult::ok(
            flavor: Integration::FLAVOR_API_KEY,
            productVersion: null,
            counts: $counts,
            callCount: $client->callCount(),
            notes: $notes,
        );
    }
}
