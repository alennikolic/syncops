<?php
// src/Web/Controller/AssetOpsController.php
//
// Stranica AssetOPS: referentni spiskovi prikupljeni iz AssetOPS-a i
// mapiranje vCenter objekata na njihove tipove.
//
// Zasto je prikupljanje ovde sinhrono, a vCenter-ovo ide kroz workera: pet
// poziva nad malim spiskovima traje manje od sekunde i ne moze da probije
// trajanje web zahteva. Red cekanja bi ovde bio samo sloj izmedju klika i
// rezultata.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Integration\Integration;
use SyncOps\Integration\IntegrationRepository;
use SyncOps\Integration\IntegrationType;
use SyncOps\Source\AssetOps\AssetOpsException;
use SyncOps\Source\AssetOps\CiCollector;
use SyncOps\Source\AssetOps\ClientFactory;
use SyncOps\Source\AssetOps\ReferenceCollector;
use SyncOps\Storage\AssetOpsRepository;
use SyncOps\Sync\CiOverview;
use SyncOps\Sync\FieldCatalog;
use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;
use Throwable;

final class AssetOpsController
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly AssetOpsRepository $repo,
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Flash $flash,
        private readonly ClientFactory $factory = new ClientFactory(),
        private readonly ReferenceCollector $collector = new ReferenceCollector(),
        private readonly CiCollector $cis = new CiCollector(),
    ) {
    }

    /**
     * Referentni spiskovi prikupljeni iz AssetOPS-a.
     */
    public function index(): void
    {
        $selected = $this->selectedOrEmpty('assetops');

        if ($selected === null) {
            return;
        }

        $tables = [];

        foreach (array_keys(ReferenceCollector::SOURCES) as $kind) {
            $tables[$kind] = $this->repo->byKind($selected->id, $kind);
        }

        $this->view->render('assetops/index', [
            'title'        => 'Referentni podaci',
            'nav'          => 'assetops',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'tables'       => $tables,
            'lastSeen'     => $this->repo->lastSeenByKind($selected->id),
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Mapiranje: tip CI-ja i tabela polja, po vrsti izvora.
     *
     * Zasebna stranica a ne odeljak ispod spiskova — mapiranje se podesava
     * jednom i onda retko dira, a spiskovi se gledaju. Dve stvari sa
     * razlicitim ritmom ne treba da dele ekran.
     */
    public function mapping(): void
    {
        $selected = $this->selectedOrEmpty('assetops-mapping');

        if ($selected === null) {
            return;
        }

        $this->view->render('assetops/mapping', [
            'title'        => 'Mapiranje',
            'nav'          => 'assetops-mapping',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'mapping'      => $this->repo->mapping($selected->id),
            'types'        => [
                FieldCatalog::KIND_VM   => $this->repo->options($selected->id, 'virtual_asset_type'),
                FieldCatalog::KIND_HOST => $this->repo->options($selected->id, 'asset_type'),
            ],
            'fields'       => [
                FieldCatalog::KIND_VM   => $this->targets($selected->id, FieldCatalog::KIND_VM),
                FieldCatalog::KIND_HOST => $this->targets($selected->id, FieldCatalog::KIND_HOST),
            ],
            'sourceGroups' => [
                FieldCatalog::KIND_VM   => FieldCatalog::sourceGroups(FieldCatalog::KIND_VM),
                FieldCatalog::KIND_HOST => FieldCatalog::sourceGroups(FieldCatalog::KIND_HOST),
            ],
            'policies'     => FieldCatalog::POLICIES,
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Zateceno stanje u AssetOPS-u: koji CI-jevi tamo postoje.
     *
     * Ovo je cetvrti deo slike, uz inventar, referentne spiskove i mapiranje.
     * Ima vrednost i pre ijednog upisa: vidi se koliko CI-jeva nosi nas kljuc
     * spajanja, koliko ih je mapiranog tipa, i gde se te dve stvari ne
     * poklapaju.
     */
    public function ci(): void
    {
        $selected = $this->selectedOrEmpty('assetops-ci');

        if ($selected === null) {
            return;
        }

        $mapping = $this->repo->mapping($selected->id);
        $refs    = $this->repo->correlationRefs($selected->id);
        $stats   = $this->repo->ciStats($selected->id);

        $panels = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $rows     = $this->repo->ciByEntity($selected->id, $entity);
            $typeSlug = $mapping[$sourceKind]['type_slug'] ?? null;
            $typeList = $this->repo->options(
                $selected->id,
                $sourceKind === FieldCatalog::KIND_VM ? 'virtual_asset_type' : 'asset_type'
            );

            $summary = CiOverview::summarize($rows, $typeSlug);

            $panels[$sourceKind] = [
                'entity'      => $entity,
                'label'       => $sourceKind === FieldCatalog::KIND_VM
                    ? 'Virtuelne mašine (virtual-asset)'
                    : 'Fizička oprema (asset)',
                'rows'        => $rows,
                'stats'       => $stats[$entity] ?? null,
                'type_slug'   => $typeSlug,
                'type_name'   => $typeSlug === null ? null : ($typeList[$typeSlug] ?? $typeSlug),
                'correlation' => $refs[$sourceKind] ?? null,
                'counts'      => $summary['counts'],
                'mismatched'  => $summary['mismatched'],
                'duplicates'  => $this->repo->ciDuplicateKeys($selected->id, $entity),
            ];
        }

        $this->view->render('assetops/ci', [
            'title'        => 'CI-jevi u AssetOPS-u',
            'nav'          => 'assetops-ci',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'panels'       => $panels,
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Izabrana AssetOPS integracija, ili prikaz praznog stanja.
     *
     * Vraca null kada nema nijedne — pozivalac tada samo izlazi, jer je
     * stranica vec iscrtana.
     */
    private function selectedOrEmpty(string $nav): ?Integration
    {
        $all = $this->assetOpsIntegrations();

        if ($all === []) {
            $this->view->render('assetops/empty', [
                'title' => 'AssetOPS',
                'nav'   => $nav,
            ]);

            return null;
        }

        return $this->selected($all);
    }

    /**
     * Procitaj referentne spiskove i upisi ih. Isto radi i dugme „Prikupi"
     * na stranici Integracije.
     */
    public function refresh(string $id): void
    {
        $integration = $this->requireAssetOps($id);

        if ($integration === null) {
            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /assetops');

            return;
        }

        try {
            $client = $this->factory->make($integration, $this->integrations->password($integration->id));
            $data   = $this->collector->collect($client);

            $parts = [];

            foreach ($data as $kind => $rows) {
                $stats   = $this->repo->storeKind($integration->id, $kind, $rows);
                $parts[] = sprintf(
                    '%s %d%s',
                    $this->label($kind),
                    $stats['seen'],
                    $stats['new'] > 0 ? " (novo {$stats['new']})" : ''
                );
            }

            // CI-jevi se citaju u istom prolazu. Referentni spiskovi su tek
            // vokabular; bez zatecenog stanja odredista nema s cim da se
            // poredi, pa dva dugmeta za dve polovine iste slike ne bi imala
            // smisla.
            //
            // Kljuc spajanja se cita iz mapiranja PRE prikupljanja: tako se
            // vrednost kljuca izvlaci u kolonu odmah, a ne naknadnim
            // prolaskom kroz payload.
            foreach ($this->cis->collect($client, $this->entityRefs($integration->id)) as $entity => $rows) {
                $stats   = $this->repo->storeCi($integration->id, $entity, $rows);
                $parts[] = sprintf(
                    '%s %d%s',
                    $this->label($entity),
                    $stats['seen'],
                    $stats['new'] > 0 ? " (novo {$stats['new']})" : ''
                );
            }

            $this->flash->success(sprintf(
                'Prikupljeno iz „%s” u %d poziva — %s.',
                $integration->name,
                $client->callCount(),
                implode(', ', $parts)
            ));
        } catch (AssetOpsException $e) {
            $this->flash->error($e->getMessage());
        } catch (Throwable $e) {
            error_log('SyncOPS AssetOPS prikupljanje: ' . $e->getMessage());
            $this->flash->error('Neočekivana greška: ' . $e->getMessage());
        }

        header('Location: /assetops?integration=' . $integration->id);
    }

    /**
     * Snimi ceo ekran mapiranja: tip CI-ja i sva polja, za obe vrste izvora.
     *
     * Jedna akcija a ne dve, jer je i obrazac jedan — tip i polja se biraju
     * zajedno i nema smisla da se cuvaju odvojeno.
     */
    public function saveMapping(string $id): void
    {
        $integration = $this->requireAssetOps($id);

        if ($integration === null) {
            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /assetops');

            return;
        }

        $sent    = (array) ($_POST['map'] ?? []);
        $keys    = (array) ($_POST['corr'] ?? []);
        $counted = 0;

        foreach ([FieldCatalog::KIND_VM, FieldCatalog::KIND_HOST] as $sourceKind) {
            $this->repo->saveMapping(
                integrationId: $integration->id,
                sourceKind: $sourceKind,
                typeSlug: trim((string) ($_POST[$sourceKind . '_type'] ?? '')),
            );

            $rows = $this->rowsFromForm(
                (array) ($sent[$sourceKind] ?? []),
                (string) ($keys[$sourceKind] ?? '')
            );

            $this->repo->saveFieldMap($integration->id, $sourceKind, $rows);

            $counted += count($rows);
        }

        $this->flash->success("Mapiranje je sačuvano — {$counted} podešenih polja.");
        header('Location: /assetops/mapping?integration=' . $integration->id);
    }

    /**
     * Redovi obrasca u oblik koji repozitorijum upisuje.
     *
     * @param array<string, mixed> $sent
     * @return list<array<string, mixed>>
     */
    private function rowsFromForm(array $sent, string $correlationRef): array
    {
        $rows = [];

        foreach ($sent as $target => $values) {
            // Kljuc obrasca je "vrsta:ime"; sve posle prve dvotacke je ime,
            // jer slug sme da je sadrzi.
            [$targetKind, $targetKey] = array_pad(explode(':', (string) $target, 2), 2, '');

            if (!in_array($targetKind, ['native', 'eav', 'relation'], true) || $targetKey === '' || !is_array($values)) {
                continue;
            }

            $policy = (string) ($values['policy'] ?? 'never');

            // Red daje ILI fiksnu vrednost ILI polje iz vCentera, nikad oboje.
            // Fiksnu imaju veze (slug iz prikupljenog spiska) i polja kojima u
            // vCenteru nema parnjaka, kao sto je broj rek jedinica.
            $constant = '';
            $source   = '';

            if (array_key_exists('fixed', $values)) {
                $fixed = $values['fixed'];

                $constant = is_array($fixed)
                    ? (string) json_encode(array_values(array_filter(
                        $fixed,
                        static fn (mixed $v): bool => is_string($v) && $v !== ''
                    )))
                    : trim((string) $fixed);

                if ($constant === '[]') {
                    $constant = '';
                }
            } else {
                $source = trim((string) ($values['source'] ?? ''));
            }

            $rows[] = [
                'target_kind'    => $targetKind,
                'target_key'     => $targetKey,
                'source_field'   => $source,
                'constant_value' => $constant,
                'write_policy'   => isset(FieldCatalog::POLICIES[$policy]) ? $policy : 'never',
                'is_correlation' => $correlationRef === (string) $target,
            ];
        }

        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['write_policy'] !== 'never' || $r['is_correlation']
        ));
    }

    // -----------------------------------------------------------------

    /**
     * Spisak ciljnih polja za jednu vrstu izvora: prvo nativna, pa EAV
     * atributi primenjivi na odgovarajuci entitet.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(int $integrationId, string $sourceKind): array
    {
        $map  = $this->repo->fieldMap($integrationId, $sourceKind);
        $rows = [];

        // Veze idu prve: bez lokacije i visibility grupe kreiranje CI-ja pada,
        // pa treba da budu prvo sto se vidi.
        foreach (FieldCatalog::relations($sourceKind) as $key => [$label, $listKind, $multi, $required]) {
            $row = $this->target('relation', $key, $label, '', [], $map);

            $row['options']  = $this->repo->options($integrationId, $listKind);
            $row['multi']    = $multi;
            $row['required'] = $required;
            $row['fixed']    = $this->decodeFixed((string) ($row['constant_value'] ?? ''), $multi);

            $rows[] = $row;
        }

        foreach (FieldCatalog::nativeFields($sourceKind) as $key => [$label, $type, $valueMode]) {
            $row = $this->target('native', $key, $label, $type, [], $map);

            // Polje bez parnjaka u vCenteru upisuje se rukom.
            $row['value_mode'] = $valueMode;
            $row['fixed']      = $valueMode === 'constant' && (string) ($row['constant_value'] ?? '') !== ''
                ? [(string) $row['constant_value']]
                : [];

            $rows[] = $row;
        }

        foreach ($this->repo->attributesForEntity($integrationId, FieldCatalog::entity($sourceKind)) as $attribute) {
            $rows[] = $this->target(
                'eav',
                (string) $attribute['slug'],
                (string) $attribute['name'],
                (string) ($attribute['attribute_type'] ?? ''),
                (array) ($attribute['possible_values'] ?? []),
                $map
            );
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $map
     * @return array<string, mixed>
     */
    private function target(
        string $kind,
        string $key,
        string $label,
        string $type,
        array $possibleValues,
        array $map,
    ): array {
        $ref     = $kind . ':' . $key;
        $current = $map[$ref] ?? null;

        return [
            'ref'             => $ref,
            'kind'            => $kind,
            'value_mode'      => $kind === 'relation' ? 'list' : 'source',
            'options'         => [],
            'multi'           => false,
            'required'        => false,
            'fixed'           => [],
            'key'             => $key,
            'label'           => $label,
            'type'            => $type,
            'possible_values' => $possibleValues,
            'source_field'    => $current['source_field'] ?? null,
            'constant_value'  => $current['constant_value'] ?? null,
            'write_policy'    => $current['write_policy'] ?? 'never',
            'is_correlation'  => $current['is_correlation'] ?? false,
        ];
    }

    /**
     * Zapamcena vrednost veze: jedan slug, ili niz slugova kod visestruke.
     *
     * @return list<string>
     */
    private function decodeFixed(string $raw, bool $multi): array
    {
        if ($raw === '') {
            return [];
        }

        if (!$multi) {
            return [$raw];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_map(strval(...), $decoded))
            : [$raw];
    }

    /**
     * @return list<Integration>
     */
    private function assetOpsIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations->all(),
            static fn (Integration $i): bool => $i->type === IntegrationType::ASSETOPS
        ));
    }

    /**
     * @param list<Integration> $all
     */
    private function selected(array $all): Integration
    {
        $wanted = (int) ($_GET['integration'] ?? 0);

        foreach ($all as $integration) {
            if ($integration->id === $wanted) {
                return $integration;
            }
        }

        return $all[0];
    }

    private function requireAssetOps(string $id): ?Integration
    {
        $integration = $this->integrations->find((int) $id);

        if ($integration === null || $integration->type !== IntegrationType::ASSETOPS) {
            http_response_code(404);
            $this->flash->error('Ta AssetOPS integracija ne postoji.');
            header('Location: /assetops');

            return null;
        }

        return $integration;
    }

    /**
     * Kljuc spajanja po ENTITETU, kako ga CiCollector trazi.
     *
     * Mapiranje ga vodi po vrsti izvora (vm, host), a citanje po entitetu
     * (virtual-asset, asset) — jedno u jedno, ali imena su razlicita.
     *
     * @return array<string, ?string>
     */
    private function entityRefs(int $integrationId): array
    {
        $refs    = $this->repo->correlationRefs($integrationId);
        $byEntity = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $byEntity[$entity] = $refs[$sourceKind] ?? null;
        }

        return $byEntity;
    }

    private function label(string $kind): string
    {
        return [
            'location'           => 'lokacije',
            'status'             => 'statusi',
            'visibility_group'   => 'grupe',
            'virtual_asset_type' => 'tipovi mašina',
            'asset_type'         => 'tipovi opreme',
            'eav_attribute'      => 'atributi',
            'virtual-asset'      => 'CI mašine',
            'asset'              => 'CI opreme',
        ][$kind] ?? $kind;
    }
}
