<?php
// src/Sync/Applier.php
//
// Jedino mesto u SyncOPS-u koje nesto MENJA u AssetOPS-u.
//
// Sve do ovde je citanje i racun. Zato su ovde zastite koje drugde ne bi
// imale smisla.
//
// CI SE CITA PONOVO, PRED SAM UPIS
//
// Plan je racunat iz ao_ci, a to je snimak od poslednjeg prikupljanja.
// Izmedju snimka i upisa zapis je mogao da se promeni. Zato se pred svaki
// PUT CI procita iznova i telo sastavi od onoga sto je TADA tamo — ne od
// onoga sto je bilo kad se plan racunao.
//
// Posledica je i da izmena koja vise nije potrebna nestane sama: ponovni
// racun nad svezim zapisom vrati „nema sta da se radi", i upis se preskoci.
// To je jedan poziv vise po izmeni, i vredi ga.
//
// PRVI PROLAZ JE OGRANICEN, JEDNOM
//
// Rizik celog posla skupljen je u prvi upis: 156 poziva u tudjem
// produkcionom CMDB-u, bez grupnog brisanja ako mapiranje nije valjalo. Ako
// jos nijedan upis nije napravljen, prolazi samo nekoliko zapisa — da se
// pogledaju u AssetOPS-u pre ostatka. Posle toga ogranicenje ne vazi: peti
// ispravan CI kaze sve sto bi rekao i sto pedeset prvi.
//
// SVAKI POZIV SE UPISUJE U DNEVNIK
//
// I uspeh i neuspeh, sa telom koje je poslato. Posle greske se inace ne zna
// ni sta je proslo ni sta treba ponoviti.

declare(strict_types=1);

namespace SyncOps\Sync;

use SyncOps\Source\AssetOps\AssetOpsClient;
use SyncOps\Source\AssetOps\AssetOpsException;
use SyncOps\Storage\LinkRepository;
use SyncOps\Storage\SyncActionRepository;
use Throwable;

final class Applier
{
    /** Koliko zapisa prolazi u prvom upisu ikada. */
    public const PILOT = 5;

    /** Gornja granica po jednom pritisku dugmeta, posle pilota. */
    public const BATCH = 25;

    /** Ruta po vrsti izvora. */
    private const PATHS = [
        FieldCatalog::KIND_VM   => '/api/virtual-assets/',
        FieldCatalog::KIND_HOST => '/api/assets/',
    ];

    public function __construct(
        private readonly AssetOpsClient $client,
        private readonly LinkRepository $links,
        private readonly SyncActionRepository $journal,
    ) {
    }

    /**
     * Izvrsi do $limit akcija iz plana.
     *
     * @param list<array<string, mixed>>          $actions
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, string>               $attributeTypes
     *
     * @return array{created: int, updated: int, skipped: int, failed: int,
     *               remaining: int, notes: list<string>}
     */
    public function apply(
        int $integrationId,
        string $sourceKind,
        array $actions,
        array $fieldMap,
        array $attributeTypes,
        string $typeSlug,
        int $limit,
    ): array {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                   'remaining' => 0, 'notes' => []];

        $done = 0;

        foreach ($actions as $action) {
            if ($done >= $limit) {
                $result['remaining']++;

                continue;
            }

            $done++;

            try {
                $outcome = $action['action'] === 'create'
                    ? $this->createOne($integrationId, $sourceKind, $action, $typeSlug)
                    : $this->updateOne($integrationId, $sourceKind, $action, $fieldMap, $attributeTypes, $typeSlug);
            } catch (AssetOpsException $e) {
                $outcome = $this->fail($integrationId, $sourceKind, $action, $e->getMessage());
            } catch (Throwable $e) {
                // Neocekivana greska ne sme da obori ceo prolaz: ostali
                // zapisi su nezavisni i treba da prodju.
                $outcome = $this->fail($integrationId, $sourceKind, $action, 'Neočekivana greška: ' . $e->getMessage());
            }

            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * Koliko akcija sme da prodje u ovom pritisku.
     *
     * @return array{limit: int, pilot: bool}
     */
    public function allowance(int $integrationId, ?int $wanted = null): array
    {
        if (!$this->journal->hasApplied($integrationId)) {
            return ['limit' => self::PILOT, 'pilot' => true];
        }

        return ['limit' => $wanted ?? self::BATCH, 'pilot' => false];
    }

    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $action
     */
    private function createOne(int $integrationId, string $sourceKind, array $action, string $typeSlug): string
    {
        $path = self::PATHS[$sourceKind];
        $body = $action['body'];

        $response = $this->client->raw($path, 'POST', $body);
        $data     = is_array($response['data'] ?? null) ? $response['data'] : [];
        $uuid     = isset($data['uuid']) ? (string) $data['uuid'] : '';

        if ($uuid === '') {
            // Odgovor bez uuid-a znaci da je CI mozda napravljen a mi ne
            // znamo koji je. Sledeci prolaz ga nalazi po kljucu spajanja,
            // jer je kljuc bio u telu — zato se kljuc i salje pri kreiranju.
            $this->journal->record($integrationId, $sourceKind, $action, 'create', 'failed',
                (int) ($response['status'] ?? 0),
                'Odgovor na kreiranje ne sadrži uuid. Proveri u AssetOPS-u da li je zapis nastao.',
                $body, null);

            return 'failed';
        }

        $this->links->create($integrationId, $sourceKind, [[
            'source_id'  => (int) $action['source']['id'],
            'ci_uuid'    => $uuid,
            'matched_by' => 'correlation',
            'confirmed'  => true,
        ]]);

        $this->links->recordPush($integrationId, $sourceKind, (int) $action['source']['id'], $body);

        $this->journal->record($integrationId, $sourceKind, $action, 'create', 'applied',
            (int) ($response['status'] ?? 0), (string) ($response['message'] ?? ''), $body, $uuid);

        return 'created';
    }

    /**
     * @param array<string, mixed>                $action
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, string>               $attributeTypes
     */
    private function updateOne(
        int $integrationId,
        string $sourceKind,
        array $action,
        array $fieldMap,
        array $attributeTypes,
        string $typeSlug,
    ): string {
        $uuid = (string) $action['ci']['uuid'];
        $path = self::PATHS[$sourceKind] . rawurlencode($uuid) . '/';

        // Sveze stanje, pa ponovni racun nad njim.
        $fresh = $this->client->get($path);

        $recomputed = Planner::forObject(
            $action['source'],
            ['uuid' => $uuid, 'name' => (string) ($fresh['name'] ?? ''), 'payload' => (string) json_encode($fresh)],
            ['ci_uuid' => $uuid, 'pushed_payload' => null],
            $fieldMap,
            $attributeTypes,
            $sourceKind,
            $typeSlug
        );

        if ($recomputed === null) {
            $this->journal->record($integrationId, $sourceKind, $action, 'update', 'skipped',
                null, 'U međuvremenu je već usklađeno — ništa nije poslato.', null, $uuid);

            return 'skipped';
        }

        if ($recomputed['action'] === 'skip') {
            $this->journal->record($integrationId, $sourceKind, $action, 'update', 'skipped',
                null, (string) $recomputed['reason'], null, $uuid);

            return 'skipped';
        }

        $body     = $recomputed['body'];
        $response = $this->client->raw($path, 'PUT', $body);

        $this->links->recordPush($integrationId, $sourceKind, (int) $action['source']['id'], $body);

        $this->journal->record($integrationId, $sourceKind, $action, 'update', 'applied',
            (int) ($response['status'] ?? 0), (string) ($response['message'] ?? ''), $body, $uuid);

        return 'updated';
    }

    /**
     * @param array<string, mixed> $action
     */
    private function fail(int $integrationId, string $sourceKind, array $action, string $message): string
    {
        $this->journal->record(
            $integrationId,
            $sourceKind,
            $action,
            (string) $action['action'],
            'failed',
            null,
            $message,
            $action['body'] ?? null,
            isset($action['ci']['uuid']) ? (string) $action['ci']['uuid'] : null
        );

        return 'failed';
    }
}
