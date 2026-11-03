<?php
// src/Run/Worker.php
//
// Petlja koja uzima poslove iz reda cekanja i izvrsava ih.
//
// Zasto uopste postoji: prikupljanje na vecem vCenteru traje minutima i ne sme
// da ide kroz web zahtev. GUI zato samo upisuje posao sa statusom queued, a
// worker ga pokupi.
//
// Gasenje: na SIGTERM (docker compose stop/restart) worker ZAVRSAVA tekuci
// posao pa tek onda izlazi. Prekid nasred posla bi ostavio red u stanju
// running i time trajno zakljucao integraciju, jer active_lock drzi bravu dok
// je status queued ili running.

declare(strict_types=1);

namespace SyncOps\Run;

use SyncOps\Integration\IntegrationRepository;
use Throwable;

final class Worker
{
    private bool $shouldStop = false;

    /**
     * @param callable(string): void $log
     */
    public function __construct(
        private readonly RunRepository $runs,
        private readonly IntegrationRepository $integrations,
        private readonly DiscoveryRunner $runner,
        private readonly \Closure $log,
        private readonly int $pollSeconds = 5,
        private readonly int $staleMinutes = 30,
    ) {
    }

    public function run(): void
    {
        $this->installSignalHandlers();

        ($this->log)('Worker je pokrenut. Red se proverava na svakih ' . $this->pollSeconds . ' s.');

        $idleSince = null;

        while (!$this->shouldStop) {
            // Oslobodi poslove koje je ubijeni worker ostavio zakljucane.
            foreach ($this->runs->failStale($this->staleMinutes) as $freed) {
                ($this->log)("Posao #{$freed} je oznacen kao neuspeo — bio je zaglavljen bez znaka zivota.");
            }

            $claimed = $this->runs->claimNext();

            if ($claimed === null) {
                if ($idleSince === null) {
                    $idleSince = time();
                    ($this->log)('Red je prazan.');
                }

                $this->sleep($this->pollSeconds);

                continue;
            }

            $idleSince = null;

            $this->process($claimed);
        }

        ($this->log)('Worker je zaustavljen.');
    }

    /**
     * @param array<string, mixed> $run
     */
    private function process(array $run): void
    {
        $runId         = (int) $run['id'];
        $integrationId = (int) $run['integration_id'];

        $integration = $this->integrations->find($integrationId);

        if ($integration === null) {
            $this->runs->finish($runId, RunStatus::FAILED, "Integracija {$integrationId} vise ne postoji.");
            ($this->log)("Posao #{$runId}: integracija {$integrationId} ne postoji.");

            return;
        }

        ($this->log)("Posao #{$runId}: pocinjem prikupljanje sa {$integration->baseUrl()}.");

        try {
            $outcome = $this->runner->execute($runId, $integration);

            ($this->log)(sprintf(
                'Posao #%d: %s za %.1f s, %d poziva. %s',
                $runId,
                strtoupper($outcome->status),
                $outcome->collection->durationSeconds,
                $outcome->collection->apiCalls,
                $outcome->summary()
            ));
        } catch (Throwable $e) {
            // execute() je vec upisao status failed; ovde se samo zabelezi.
            ($this->log)("Posao #{$runId}: NEUSPEH — " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            ($this->log)(
                'Upozorenje: pcntl ekstenzija nije dostupna, pa se gasenje ne obradjuje. '
                . 'Prekid nasred posla ostavlja zakljucanu integraciju do isteka od '
                . $this->staleMinutes . ' minuta.'
            );

            return;
        }

        pcntl_async_signals(true);

        $stop = function (int $signal): void {
            $this->shouldStop = true;
            ($this->log)('Primljen signal ' . $signal . ' — zavrsavam tekuci posao pa izlazim.');
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    /** Spavanje u sekundama, da signal za gasenje ne ceka ceo interval. */
    private function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldStop; $i++) {
            sleep(1);
        }
    }
}
