<?php
// src/Web/Controller/InventoryController.php
//
// Prikaz prikupljenih podataka: klasteri, hostovi, masine i detalji masine.
//
// Sve stranice dobijaju mapu poslednjih uspesnih run-ova, da bi mogle da
// oznace objekat koji u njemu nije vidjen.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Storage\InventoryQuery;
use SyncOps\Web\View;

final class InventoryController
{
    public function __construct(
        private readonly InventoryQuery $query,
        private readonly View $view,
    ) {
    }

    public function clusters(): void
    {
        $this->view->render('inventory/clusters', [
            'title'    => 'Klasteri',
            'nav'      => 'clusters',
            'clusters' => $this->query->clusters(),
            'lastRuns' => $this->query->lastSuccessfulRuns(),
        ]);
    }

    public function hosts(): void
    {
        $filters = ['search' => trim((string) ($_GET['q'] ?? ''))];
        $result  = $this->query->hosts($filters, (int) ($_GET['page'] ?? 1));

        $this->view->render('inventory/hosts', [
            'title'    => 'Hostovi',
            'nav'      => 'hosts',
            'result'   => $result,
            'filters'  => $filters,
            'lastRuns' => $this->query->lastSuccessfulRuns(),
        ]);
    }

    public function vms(): void
    {
        $filters = [
            'search'  => trim((string) ($_GET['q'] ?? '')),
            'power'   => (string) ($_GET['power'] ?? ''),
            'guest'   => (string) ($_GET['guest'] ?? ''),
            'cluster' => (string) ($_GET['cluster'] ?? ''),
            'host'    => (string) ($_GET['host'] ?? ''),
        ];

        $result = $this->query->vms($filters, (int) ($_GET['page'] ?? 1));

        $this->view->render('inventory/vms', [
            'title'    => 'Virtuelne mašine',
            'nav'      => 'vms',
            'result'   => $result,
            'filters'  => $filters,
            'options'  => $this->query->vmFilterOptions(),
            'lastRuns' => $this->query->lastSuccessfulRuns(),
        ]);
    }

    public function vm(string $id): void
    {
        $vm = $this->query->vm((int) $id);

        if ($vm === null) {
            http_response_code(404);
            header('Location: /vms');

            return;
        }

        $this->view->render('inventory/vm', [
            'title'    => (string) $vm['name'],
            'nav'      => 'vms',
            'vm'       => $vm,
            'changes'  => $this->query->vmChanges((int) $vm['id']),
            'lastRuns' => $this->query->lastSuccessfulRuns(),
        ]);
    }
}
