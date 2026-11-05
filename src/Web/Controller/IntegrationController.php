<?php
// src/Web/Controller/IntegrationController.php
//
// CRUD nad integracijama. Brisanja nema — samo iskljucivanje, u skladu sa
// ON DELETE RESTRICT iz seme: integracija sa istorijom se ne brise.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Integration\IntegrationType;
use SyncOps\Source\SourceTester;
use SyncOps\Run\DiscoveryRunner;
use SyncOps\Run\RunStatus;
use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;
use Throwable;

final class IntegrationController
{
    /**
     * @param array<string, SourceTester> $testers Provera konekcije po tipu
     *        integracije. Mapa a ne grananje: nov tip se dodaje jednim
     *        unosom pri sastavljanju, bez diranja ovog kontrolera.
     */
    public function __construct(
        private readonly IntegrationRepository $repo,
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Flash $flash,
        private readonly array $testers,
        private readonly DiscoveryRunner $runner,
    ) {
    }

    // -----------------------------------------------------------------

    public function index(): void
    {
        $this->view->render('integration/index', [
            'title'        => 'Integracije',
            'nav'          => 'integrations',
            'integrations' => $this->repo->all(),
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Forma za nov zapis. Tip stize iz putanje, jer se vCenter i AssetOPS
     * ne unose istim poljima.
     */
    public function createForm(string $type = IntegrationType::VCENTER): void
    {
        if (!IntegrationType::exists($type)) {
            $this->flash->error('Nepoznat tip integracije.');
            header('Location: /integrations');

            return;
        }

        $this->form(null, ['type' => $type], []);
    }

    public function editForm(string $id): void
    {
        $integration = $this->repo->find((int) $id);

        if ($integration === null) {
            $this->notFound();

            return;
        }

        $this->form($integration, [], []);
    }

    // -----------------------------------------------------------------

    public function create(): void
    {
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /integrations/new');

            return;
        }

        $input  = $this->input();
        $errors = $this->validate($input, requirePassword: true);

        if ($errors !== []) {
            $this->form(null, $input, $errors);

            return;
        }

        try {
            $id = $this->repo->create(
                name:       $input['name'],
                hostname:   $input['hostname'],
                username:   $input['username'],
                password:   $input['password'],
                port:       (int) $input['port'],
                type:       $input['type'],
                apiFlavor:  $input['api_flavor'],
                verifyPeer: $input['verify_peer'] === '1',
            );
        } catch (Throwable $e) {
            $this->form(null, $input, [$e->getMessage()]);

            return;
        }

        $this->flash->success(
            "Integracija „{$input['name']}” je dodata. Proveri konekciju pre prvog posla."
        );
        header('Location: /integrations');
        unset($id);
    }

    public function update(string $id): void
    {
        $integration = $this->repo->find((int) $id);

        if ($integration === null) {
            $this->notFound();

            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /integrations/' . $integration->id . '/edit');

            return;
        }

        $input  = $this->input();
        $errors = $this->validate($input, requirePassword: false);

        if ($errors !== []) {
            $this->form($integration, $input, $errors);

            return;
        }

        try {
            $this->repo->update(
                id:         $integration->id,
                name:       $input['name'],
                hostname:   $input['hostname'],
                port:       (int) $input['port'],
                username:   $input['username'],
                apiFlavor:  $input['api_flavor'],
                verifyPeer: $input['verify_peer'] === '1',
                type:       $integration->type,
            );

            // Prazno polje znaci "ostavi postojecu lozinku".
            if ($input['password'] !== '') {
                $this->repo->updatePassword($integration->id, $input['password']);
            }
        } catch (Throwable $e) {
            $this->form($integration, $input, [$e->getMessage()]);

            return;
        }

        $this->flash->success('Izmene su sačuvane.');
        header('Location: /integrations');
    }

    /**
     * Provera konekcije. Traje nekoliko sekundi, pa se radi sinhrono — za
     * razliku od discovery-ja, koji ide kroz CLI worker jer ume da traje
     * minutima i probije ogranicenje trajanja web zahteva.
     */
    public function test(string $id): void
    {
        $integration = $this->repo->find((int) $id);

        if ($integration === null) {
            $this->notFound();

            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /integrations');

            return;
        }

        $tester = $this->testers[$integration->type] ?? null;

        if ($tester === null) {
            $this->flash->error(sprintf(
                'Za tip „%s” nije podešena provera konekcije.',
                IntegrationType::label($integration->type)
            ));
            header('Location: /integrations');

            return;
        }

        $result = $tester->test($integration);

        if ($result->ok) {
            $this->flash->success(sprintf(
                'Veza sa „%s” radi (%s%s). Pronađeno — %s',
                $integration->name,
                IntegrationType::flavorLabel($integration->type, $result->flavor),
                $result->productVersion !== null ? ' ' . $result->productVersion : '',
                $result->message
            ));
        } else {
            $this->flash->error(sprintf(
                'Veza sa „%s” ne radi: %s',
                $integration->name,
                $result->message
            ));
        }

        header('Location: /integrations');
    }

    /**
     * Stavi prikupljanje u red cekanja.
     *
     * Ne izvrsava se ovde: na vecem vCenteru prikupljanje traje minutima i
     * probilo bi ogranicenje trajanja web zahteva. Posao preuzima worker.
     */
    public function discover(string $id): void
    {
        $integration = $this->repo->find((int) $id);

        if ($integration === null) {
            $this->notFound();

            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /integrations');

            return;
        }

        if (!$integration->isActive) {
            $this->flash->error("Integracija „{$integration->name}” je isključena. Uključi je pa pokušaj ponovo.");
            header('Location: /integrations');

            return;
        }

        // Prikupljanje za sada postoji samo za vCenter. AssetOPS se cita u
        // sloju sinhronizacije, koji jos nije napravljen.
        if ($integration->type !== IntegrationType::VCENTER) {
            $this->flash->error(sprintf(
                'Prikupljanje za „%s” još ne postoji.',
                IntegrationType::label($integration->type)
            ));
            header('Location: /integrations');

            return;
        }

        try {
            $runId = $this->runner->enqueue($integration, RunStatus::TRIGGER_GUI);

            $this->flash->success(sprintf(
                'Prikupljanje za „%s” je u redu čekanja kao posao #%d. Preuzeće ga worker.',
                $integration->name,
                $runId
            ));

            header('Location: /runs');
        } catch (Throwable $e) {
            $this->flash->error($e->getMessage());
            header('Location: /integrations');
        }
    }

    public function toggle(string $id): void
    {
        $integration = $this->repo->find((int) $id);

        if ($integration === null) {
            $this->notFound();

            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /integrations');

            return;
        }

        $this->repo->setActive($integration->id, !$integration->isActive);

        $this->flash->success(sprintf(
            'Integracija „%s” je %s.',
            $integration->name,
            $integration->isActive ? 'isključena' : 'uključena'
        ));

        header('Location: /integrations');
    }

    // -----------------------------------------------------------------

    /**
     * @param array<string, string> $input
     * @param list<string> $errors
     */
    private function form(?Integration $integration, array $input, array $errors): void
    {
        $type = $integration?->type ?? ($input['type'] ?? IntegrationType::VCENTER);

        $this->view->render('integration/form', [
            'title'       => $integration === null ? 'Nova integracija' : 'Izmena integracije',
            'nav'         => 'integrations',
            'integration' => $integration,
            'type'        => $type,
            'spec'        => IntegrationType::form($type),
            'flavors'     => IntegrationType::flavors($type),
            'errors'      => $errors,
            'csrf'        => $this->auth->csrfToken(),
            'value'       => $this->values($integration, $input, $type),
        ]);
    }

    /**
     * Vrednosti za polja forme: prednost ima ono sto je korisnik upravo uneo,
     * pa postojeci zapis, pa podrazumevano.
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function values(?Integration $integration, array $input, string $type): array
    {
        $spec = IntegrationType::form($type);

        return [
            'type'        => $type,
            'name'        => $input['name']        ?? $integration?->name       ?? '',
            'hostname'    => $input['hostname']    ?? $integration?->hostname   ?? '',
            'port'        => $input['port']        ?? (string) ($integration?->port ?? $spec['default_port']),
            'username'    => $input['username']    ?? $integration?->username   ?? '',
            'api_flavor'  => $input['api_flavor']  ?? $integration?->apiFlavor
                                                   ?? IntegrationType::defaultFlavor($type),
            'verify_peer' => $input['verify_peer'] ?? (($integration?->verifyPeer ?? false) ? '1' : '0'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function input(): array
    {
        $type = (string) ($_POST['type'] ?? IntegrationType::VCENTER);

        if (!IntegrationType::exists($type)) {
            $type = IntegrationType::VCENTER;
        }

        return [
            'type'        => $type,
            'name'        => trim((string) ($_POST['name'] ?? '')),
            'hostname'    => trim((string) ($_POST['hostname'] ?? '')),
            'port'        => trim((string) ($_POST['port'] ?? '443')),
            'username'    => trim((string) ($_POST['username'] ?? '')),
            // Kljuc se ne trimuje slepo: vodeci razmak je greska pri lepljenju,
            // ali razmak unutra moze da bude deo tajne. Skidaju se samo krajevi.
            'password'    => trim((string) ($_POST['password'] ?? ''), " \t\n\r\0\x0B"),
            'api_flavor'  => (string) ($_POST['api_flavor'] ?? IntegrationType::defaultFlavor($type)),
            'verify_peer' => ($_POST['verify_peer'] ?? '') === '1' ? '1' : '0',
        ];
    }

    /**
     * @param array<string, string> $input
     * @return list<string>
     */
    private function validate(array $input, bool $requirePassword): array
    {
        $errors = [];
        $type   = $input['type'];
        $spec   = IntegrationType::form($type);
        $noun   = $spec['noun'];

        if ($input['hostname'] === '') {
            $errors[] = "Unesi adresu — gde se nalazi {$noun}.";
        } elseif (
            filter_var($input['hostname'], FILTER_VALIDATE_IP) === false
            && filter_var($input['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            $errors[] = 'Adresa nije ispravan FQDN ni IP.';
        }

        if ($input['name'] === '') {
            $errors[] = "Unesi naziv po kojem ćeš prepoznati ovaj {$noun}.";
        }

        $port = (int) $input['port'];

        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port mora biti broj između 1 i 65535.';
        }

        // AssetOPS nema korisnicko ime — ceo kredencijal je API kljuc.
        if ($spec['has_username'] === true && $input['username'] === '') {
            $errors[] = 'Unesi korisničko ime servisnog naloga.';
        }

        if ($requirePassword && $input['password'] === '') {
            $errors[] = "Unesi {$spec['secret_label']}.";
        }

        // JWT token u polju za API kljuc: lako se pomesaju, a poruka koju
        // server tada vrati zvuci kao da je kljuc istekao. Bolje da se kaze
        // odmah nego posle neuspele provere konekcije.
        if (
            $type === IntegrationType::ASSETOPS
            && $input['password'] !== ''
            && str_starts_with($input['password'], 'eyJ')
        ) {
            $errors[] = 'Ovo izgleda kao JWT token, ne kao API ključ. '
                      . 'Ključ ima oblik prefiks.tajna i dobija se na /api/current-user/own-api-keys/.';
        }

        // Provera je vezana za tip: ne pita samo da li dijalekt postoji, nego
        // i da li vazi za ovu vrstu integracije.
        if (!IntegrationType::isValidFlavor($type, $input['api_flavor'])) {
            $errors[] = 'Izaberi protokol sa liste.';
        }

        return $errors;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->flash->error('Ta integracija ne postoji.');
        header('Location: /integrations');
    }
}
