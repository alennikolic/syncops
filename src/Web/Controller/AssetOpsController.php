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
use SyncOps\Source\AssetOps\ClientFactory;
use SyncOps\Source\AssetOps\ReferenceCollector;
use SyncOps\Storage\AssetOpsRepository;
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
    ) {
    }

    public function index(): void
    {
        $all = $this->assetOpsIntegrations();

        if ($all === []) {
            $this->view->render('assetops/empty', [
                'title' => 'AssetOPS',
                'nav'   => 'assetops',
            ]);

            return;
        }

        $selected = $this->selected($all);

        $tables = [];

        foreach (array_keys(ReferenceCollector::SOURCES) as $kind) {
            $tables[$kind] = $this->repo->byKind($selected->id, $kind);
        }

        $this->view->render('assetops/index', [
            'title'        => 'AssetOPS',
            'nav'          => 'assetops',
            'integrations' => $all,
            'integration'  => $selected,
            'tables'       => $tables,
            'lastSeen'     => $this->repo->lastSeenByKind($selected->id),
            'mapping'      => $this->repo->mapping($selected->id),
            'fields'       => [
                FieldCatalog::KIND_VM   => $this->targets($selected->id, FieldCatalog::KIND_VM),
                FieldCatalog::KIND_HOST => $this->targets($selected->id, FieldCatalog::KIND_HOST),
            ],
            'sourceGroups' => [
                FieldCatalog::KIND_VM   => FieldCatalog::sourceGroups(FieldCatalog::KIND_VM),
                FieldCatalog::KIND_HOST => FieldCatalog::sourceGroups(FieldCatalog::KIND_HOST),
            ],
            'policies'     => FieldCatalog::POLICIES,
            'options'      => [
                'virtual_asset_type' => $this->repo->options($selected->id, 'virtual_asset_type'),
                'asset_type'         => $this->repo->options($selected->id, 'asset_type'),
                'location'           => $this->repo->options($selected->id, 'location'),
                'visibility_group'   => $this->repo->options($selected->id, 'visibility_group'),
            ],
            'csrf'         => $this->auth->csrfToken(),
        ]);
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

        foreach (['vm', 'host'] as $sourceKind) {
            $units = trim((string) ($_POST[$sourceKind . '_units_required'] ?? ''));

            $this->repo->saveMapping(
                integrationId: $integration->id,
                sourceKind: $sourceKind,
                typeSlug: trim((string) ($_POST[$sourceKind . '_type'] ?? '')),
                locationSlug: trim((string) ($_POST[$sourceKind . '_location'] ?? '')),
                visibilityGroups: array_values(array_filter(
                    (array) ($_POST[$sourceKind . '_visibility_groups'] ?? []),
                    static fn (mixed $v): bool => is_string($v) && $v !== ''
                )),
                // units_required postoji samo kod opreme.
                unitsRequired: ($sourceKind === 'host' && $units !== '') ? (int) $units : null,
            );
        }

        $this->flash->success('Mapiranje je sačuvano.');
        header('Location: /assetops?integration=' . $integration->id);
    }

    /**
     * Snimi mapiranje polja i atributa za obe vrste izvora.
     */
    public function saveFields(string $id): void
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
            $rows = [];

            foreach ((array) ($sent[$sourceKind] ?? []) as $target => $values) {
                // Kljuc forme je "vrsta:ime"; sve posle prve dvotacke je ime,
                // jer slug sme da je sadrzi.
                [$targetKind, $targetKey] = array_pad(explode(':', (string) $target, 2), 2, '');

                if ($targetKind === '' || $targetKey === '' || !is_array($values)) {
                    continue;
                }

                $policy = (string) ($values['policy'] ?? 'never');

                $rows[] = [
                    'target_kind'    => $targetKind === 'eav' ? 'eav' : 'native',
                    'target_key'     => $targetKey,
                    'source_field'   => trim((string) ($values['source'] ?? '')),
                    'constant_value' => trim((string) ($values['constant'] ?? '')),
                    'write_policy'   => isset(FieldCatalog::POLICIES[$policy]) ? $policy : 'never',
                    'is_correlation' => ((string) ($keys[$sourceKind] ?? '')) === (string) $target,
                ];
            }

            $this->repo->saveFieldMap($integration->id, $sourceKind, $rows);

            $counted += count(array_filter(
                $rows,
                static fn (array $r): bool => $r['write_policy'] !== 'never' || $r['is_correlation']
            ));
        }

        $this->flash->success("Mapiranje polja je sačuvano — {$counted} podešenih.");
        header('Location: /assetops?integration=' . $integration->id);
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

        foreach (FieldCatalog::nativeFields($sourceKind) as $key => [$label, $type]) {
            $rows[] = $this->target('native', $key, $label, $type, [], $map);
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

    private function label(string $kind): string
    {
        return [
            'location'           => 'lokacije',
            'status'             => 'statusi',
            'visibility_group'   => 'grupe',
            'virtual_asset_type' => 'tipovi mašina',
            'asset_type'         => 'tipovi opreme',
        ][$kind] ?? $kind;
    }
}
