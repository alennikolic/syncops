<?php
// src/Web/Controller/DashboardController.php
//
// Pocetna strana: stanje integracija i koliko je objekata prikupljeno.
//
// Upiti su ovde direktno nad PDO-om jer inventory repozitorijumi jos ne
// postoje. Kada budu, ovo se seli u njih.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use PDO;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Web\View;

final class DashboardController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IntegrationRepository $integrations,
        private readonly View $view,
    ) {
    }

    public function index(): void
    {
        $integrations = $this->integrations->all();

        $this->view->render('dashboard', [
            'title'        => 'Pregled',
            'nav'          => 'dashboard',
            'integrations' => $integrations,
            'counts'       => [
                'Integracije'      => count($integrations),
                'Klasteri'         => $this->count('vc_cluster'),
                'Hostovi'          => $this->count('vc_host'),
                'Virtuelne mašine' => $this->count('vc_vm'),
            ],
            'lastRuns'     => $this->lastRuns(),
        ]);
    }

    private function count(string $table): int
    {
        // Ime tabele je ovde konstanta iz koda, nikada korisnicki unos.
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * Poslednji run po integraciji.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lastRuns(): array
    {
        $rows = $this->pdo->query(
            'SELECT r.integration_id, r.id, r.status, r.finished_at, r.queued_at,
                    r.clusters_found, r.hosts_found, r.vms_found
               FROM discovery_run r
               JOIN (
                    SELECT integration_id, MAX(id) AS max_id
                      FROM discovery_run
                     GROUP BY integration_id
               ) last ON last.max_id = r.id'
        )->fetchAll();

        $byIntegration = [];

        foreach ($rows as $row) {
            $byIntegration[(int) $row['integration_id']] = $row;
        }

        return $byIntegration;
    }
}
