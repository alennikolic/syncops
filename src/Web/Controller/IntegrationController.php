<?php
// src/Web/Controller/IntegrationController.php
//
// CRUD nad integracijama. Brisanja nema — samo iskljucivanje, u skladu sa
// ON DELETE RESTRICT iz seme: integracija sa istorijom se ne brise.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;
use Throwable;

final class IntegrationController
{
    private const FLAVORS = [
        Integration::FLAVOR_AUTO        => 'Prepoznaj sam',
        Integration::FLAVOR_API_V2      => '/api — vSphere 7.0 U2 i noviji',
        Integration::FLAVOR_REST_LEGACY => '/rest — vSphere 6.7 do 7.0',
    ];

    public function __construct(
        private readonly IntegrationRepository $repo,
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Flash $flash,
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

    public function createForm(): void
    {
        $this->form(null, [], []);
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
                apiFlavor:  $input['api_flavor'],
                verifyPeer: $input['verify_peer'] === '1',
            );
        } catch (Throwable $e) {
            $this->form(null, $input, [$e->getMessage()]);

            return;
        }

        $this->flash->success("Integracija „{$input['name']}” je dodata. Proveri konekciju da bi se prepoznala verzija API-ja.");
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
        $this->view->render('integration/form', [
            'title'       => $integration === null ? 'Nova integracija' : 'Izmena integracije',
            'nav'         => 'integrations',
            'integration' => $integration,
            'flavors'     => self::FLAVORS,
            'errors'      => $errors,
            'csrf'        => $this->auth->csrfToken(),
            'value'       => $this->values($integration, $input),
        ]);
    }

    /**
     * Vrednosti za polja forme: prednost ima ono sto je korisnik upravo uneo,
     * pa postojeci zapis, pa podrazumevano.
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function values(?Integration $integration, array $input): array
    {
        return [
            'name'        => $input['name']        ?? $integration?->name       ?? '',
            'hostname'    => $input['hostname']    ?? $integration?->hostname   ?? '',
            'port'        => $input['port']        ?? (string) ($integration?->port ?? 443),
            'username'    => $input['username']    ?? $integration?->username   ?? '',
            'api_flavor'  => $input['api_flavor']  ?? $integration?->apiFlavor  ?? Integration::FLAVOR_AUTO,
            'verify_peer' => $input['verify_peer'] ?? (($integration?->verifyPeer ?? false) ? '1' : '0'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function input(): array
    {
        return [
            'name'        => trim((string) ($_POST['name'] ?? '')),
            'hostname'    => trim((string) ($_POST['hostname'] ?? '')),
            'port'        => trim((string) ($_POST['port'] ?? '443')),
            'username'    => trim((string) ($_POST['username'] ?? '')),
            'password'    => (string) ($_POST['password'] ?? ''),
            'api_flavor'  => (string) ($_POST['api_flavor'] ?? Integration::FLAVOR_AUTO),
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

        if ($input['hostname'] === '') {
            $errors[] = 'Unesi adresu vCentera.';
        } elseif (
            filter_var($input['hostname'], FILTER_VALIDATE_IP) === false
            && filter_var($input['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            $errors[] = 'Adresa nije ispravan FQDN ni IP.';
        }

        if ($input['name'] === '') {
            $errors[] = 'Unesi naziv po kojem ćeš prepoznati ovaj vCenter.';
        }

        $port = (int) $input['port'];

        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port mora biti broj između 1 i 65535.';
        }

        if ($input['username'] === '') {
            $errors[] = 'Unesi korisničko ime servisnog naloga.';
        }

        if ($requirePassword && $input['password'] === '') {
            $errors[] = 'Unesi lozinku servisnog naloga.';
        }

        if (!array_key_exists($input['api_flavor'], self::FLAVORS)) {
            $errors[] = 'Izaberi verziju API-ja sa liste.';
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
