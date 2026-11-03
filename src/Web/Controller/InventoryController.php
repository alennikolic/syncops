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
use SyncOps\Web\CsvExport;
use SyncOps\Web\View;

final class InventoryController
{
    public function __construct(
        private readonly InventoryQuery $query,
        private readonly View $view,
        private readonly CsvExport $csv = new CsvExport(),
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

    /**
     * Uslovi iz URL-a. Cita ih i prikaz i izvoz, da bi izvezeno uvek
     * odgovaralo onome sto se vidi na ekranu.
     *
     * @return array<string, string>
     */
    private function vmFilters(): array
    {
        return [
            'search'  => trim((string) ($_GET['q'] ?? '')),
            'power'   => (string) ($_GET['power'] ?? ''),
            'guest'   => (string) ($_GET['guest'] ?? ''),
            'cluster' => (string) ($_GET['cluster'] ?? ''),
            'host'    => (string) ($_GET['host'] ?? ''),
        ];
    }

    public function vms(): void
    {
        $filters = $this->vmFilters();
        $result  = $this->query->vms($filters, (int) ($_GET['page'] ?? 1));

        $this->view->render('inventory/vms', [
            'title'    => 'Virtuelne mašine',
            'nav'      => 'vms',
            'result'   => $result,
            'filters'  => $filters,
            'options'  => $this->query->vmFilterOptions(),
            'lastRuns' => $this->query->lastSuccessfulRuns(),
        ]);
    }

    /**
     * Izvoz masina u CSV, sa istim uslovima koji su primenjeni na ekranu i
     * bez stranicenja — izvozi se sve sto odgovara, a ne samo tekuca strana.
     *
     * Mere se izvoze u osnovnim jedinicama (MiB, bajtovi) kao celi brojevi:
     * "4.5 GiB" bi u Excel-u sa zarezom kao decimalnim znakom stiglo kao
     * tekst, pa se po njemu ne bi moglo racunati.
     */
    public function exportVms(): void
    {
        $filters = $this->vmFilters();
        $lastRuns = $this->query->lastSuccessfulRuns();

        $header = [
            'integracija', 'moref', 'ime',
            'instance_uuid', 'bios_uuid',
            'napajanje', 'stanje_gosta',
            'os_podesen_u_vcenteru', 'os_prijavljen_iz_gosta', 'os_porodica',
            'vcpu', 'jezgara_po_soketu', 'ram_mib',
            'diskova', 'kapacitet_diskova_bajtova', 'mreznih_kartica',
            'hostname_gosta', 'ip_adresa',
            'host', 'klaster', 'datacentar',
            'verzija_hardvera',
            'prvi_put_vidjeno_utc', 'poslednji_put_vidjeno_utc',
            'poslednji_run', 'nije_u_poslednjem_runu',
        ];

        $rows = (function () use ($filters, $lastRuns): iterable {
            foreach ($this->query->vmsForExport($filters) as $vm) {
                $lastRun = $lastRuns[(int) $vm['integration_id']] ?? null;
                $missing = $lastRun !== null && (int) $vm['last_run_id'] < $lastRun;

                yield [
                    $vm['integration_name'],
                    $vm['moref'],
                    $vm['name'],
                    $vm['instance_uuid'],
                    $vm['bios_uuid'],
                    $vm['power_state'],
                    $vm['guest_state'],
                    $vm['guest_os'],
                    $vm['guest_full_name'],
                    $vm['guest_os_family'],
                    $vm['cpu_count'],
                    $vm['cpu_cores_per_socket'],
                    $vm['memory_mib'],
                    $vm['disk_count'],
                    $vm['disk_capacity_bytes'],
                    $vm['nic_count'],
                    $vm['guest_hostname'],
                    $vm['guest_ip_address'],
                    $vm['host_name'] ?? $vm['host_moref'],
                    $vm['cluster_name'] ?? $vm['cluster_moref'],
                    $vm['datacenter_moref'],
                    $vm['hardware_version'],
                    $vm['first_seen'],
                    $vm['last_seen'],
                    $vm['last_run_id'],
                    $missing ? 'da' : 'ne',
                ];
            }
        })();

        $this->csv->send(
            'syncops-masine-' . gmdate('Y-m-d-Hi') . '.csv',
            $header,
            $rows
        );
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
