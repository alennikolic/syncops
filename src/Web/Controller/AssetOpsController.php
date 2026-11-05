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
use SyncOps\Storage\InventoryQuery;
use SyncOps\Storage\LinkRepository;
use SyncOps\Sync\CiOverview;
use SyncOps\Sync\FieldCatalog;
use SyncOps\Sync\Matcher;
use SyncOps\Sync\Planner;
use SyncOps\Sync\TargetCatalog;
use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;
use Throwable;

final class AssetOpsController
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly AssetOpsRepository $repo,
        private readonly LinkRepository $links,
        private readonly InventoryQuery $inventory,
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
     * Spajanje: koji objekat iz vCentera odgovara kom CI-ju.
     *
     * Ekran ima vrednost i pre ijednog upisa — pokazuje koliko bi se
     * kreiralo, koliko povezalo i gde spajanje nije sigurno. Ništa se ne
     * upisuje u AssetOPS; jedino što nastaje su veze u našoj bazi.
     */
    public function match(): void
    {
        $selected = $this->selectedOrEmpty('assetops-match');

        if ($selected === null) {
            return;
        }

        $panels = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $panels[$sourceKind] = $this->plan($selected->id, $sourceKind, $entity);
        }

        $this->view->render('assetops/match', [
            'title'        => 'Spajanje',
            'nav'          => 'assetops-match',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'panels'       => $panels,
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Predlog spajanja za jednu vrstu izvora.
     *
     * @return array<string, mixed>
     */
    private function plan(int $integrationId, string $sourceKind, string $entity): array
    {
        $plan = Matcher::plan(
            inventory: $this->inventoryFor($sourceKind),
            cis: $this->repo->ciByEntity($integrationId, $entity),
            links: $this->links->bySourceKind($integrationId, $sourceKind),
            sourceField: $this->correlationSource($integrationId, $sourceKind),
            weakFields: FieldCatalog::weakFields($sourceKind),
        );

        return $plan + [
            'label'       => $sourceKind === FieldCatalog::KIND_VM
                ? 'Virtuelne mašine'
                : 'Hostovi',
            'source_kind' => $sourceKind,
            'correlation' => $this->correlationSource($integrationId, $sourceKind),
        ];
    }

    /**
     * Ceo inventar jedne vrste, bez straničenja.
     *
     * Spajanje mora da vidi sve: objekat koji nije u ovom spisku izgledao bi
     * kao da ne postoji, pa bi sledeći korak predložio da se kreira.
     *
     * @return list<array<string, mixed>>
     */
    private function inventoryFor(string $sourceKind): array
    {
        return array_values(iterator_to_array(
            $sourceKind === FieldCatalog::KIND_VM
                ? $this->inventory->vmsForExport()
                : $this->inventory->hostsForExport(),
            false
        ));
    }

    /**
     * Kolona inventara koja nosi vrednost ključa spajanja.
     *
     * Čita se iz mapiranja: ključ je red označen kao takav, a njegov
     * source_field je ono čime se puni. Ključ sa fiksnom vrednošću nema
     * smisla — ista vrednost na svim objektima nije ključ — pa se takav red
     * ignoriše.
     */
    private function correlationSource(int $integrationId, string $sourceKind): ?string
    {
        foreach ($this->repo->fieldMap($integrationId, $sourceKind) as $row) {
            if (($row['is_correlation'] ?? false) === true) {
                $field = (string) ($row['source_field'] ?? '');

                return $field === '' ? null : $field;
            }
        }

        return null;
    }

    /**
     * Plan: šta bi se upisalo u AssetOPS. Ništa se ne upisuje.
     *
     * Poslednji ekran pre nego što SyncOPS prvi put nešto promeni u tuđem
     * sistemu — i jedini na kom se greška u mapiranju vidi pre nego što
     * postane 156 pogrešnih zapisa.
     */
    public function planView(): void
    {
        $selected = $this->selectedOrEmpty('assetops-plan');

        if ($selected === null) {
            return;
        }

        $mapping = $this->repo->mapping($selected->id);
        $panels  = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $cis = [];

            foreach ($this->repo->ciByEntity($selected->id, $entity) as $ci) {
                $cis[(string) $ci['uuid']] = $ci;
            }

            $types = [];

            foreach ($this->repo->attributesForEntity($selected->id, $entity) as $attribute) {
                // Atribut kog vise nema u AssetOPS-u se ne upisuje. Izostaje
                // iz spiska tipova, pa ga Planner odbija sa razlogom umesto
                // da posalje vrednost u nepostojece polje.
                if (($attribute['missing'] ?? false) === true) {
                    continue;
                }

                $types[(string) $attribute['slug']] = (string) ($attribute['attribute_type'] ?? '');
            }

            $panels[$sourceKind] = Planner::plan(
                inventory: $this->inventoryFor($sourceKind),
                links: $this->links->bySourceKind($selected->id, $sourceKind),
                cis: $cis,
                fieldMap: $this->repo->fieldMap($selected->id, $sourceKind),
                attributeTypes: $types,
                sourceKind: $sourceKind,
                typeSlug: $mapping[$sourceKind]['type_slug'] ?? null,
            ) + [
                'label'       => $sourceKind === FieldCatalog::KIND_VM ? 'Virtuelne mašine' : 'Hostovi',
                'source_kind' => $sourceKind,
                'labels'      => $this->targetLabels($selected->id, $sourceKind, $entity),
            ];
        }

        $this->view->render('assetops/plan', [
            'title'        => 'Plan',
            'nav'          => 'assetops-plan',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'panels'       => $panels,
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Ime cilja za prikaz: "native:name" → „Ime". Bez ovoga bi plan govorio
     * slugovima, a čita ga čovek koji odlučuje.
     *
     * @return array<string, string>
     */
    private function targetLabels(int $integrationId, string $sourceKind, string $entity): array
    {
        $labels = [];

        foreach (FieldCatalog::relations($sourceKind) as $key => [$label]) {
            $labels['relation:' . $key] = $label;
        }

        foreach (FieldCatalog::nativeFields($sourceKind) as $key => [$label]) {
            $labels['native:' . $key] = $label;
        }

        foreach ($this->repo->attributesForEntity($integrationId, $entity) as $attribute) {
            $labels['eav:' . $attribute['slug']] = (string) $attribute['name'];
        }

        return $labels;
    }

    /**
     * Napravi veze: sve jake odjednom, ili potvrđene slabe iz obrasca.
     *
     * Jedna akcija za oba, jer je razlika samo u tome odakle spisak dolazi.
     * Jaka veza nastaje potvrđena — vrednost ključa je jednoznačna. Slaba
     * nastaje tek kad je čovek izabere, i upravo zato ovaj ekran postoji.
     */
    public function saveLinks(string $id): void
    {
        $integration = $this->requireAssetOps($id);

        if ($integration === null) {
            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /assetops/match');

            return;
        }

        $sourceKind = (string) ($_POST['source_kind'] ?? '');

        if (!isset(FieldCatalog::ENTITY[$sourceKind])) {
            $this->flash->error('Nepoznata vrsta izvora.');
            header('Location: /assetops/match');

            return;
        }

        $plan     = $this->plan($integration->id, $sourceKind, FieldCatalog::ENTITY[$sourceKind]);
        $chosen   = array_map(strval(...), (array) ($_POST['weak'] ?? []));
        $wanted   = [];

        // Predlog se ne uzima iz obrasca nego se ponovo izračuna, a iz
        // obrasca dolazi samo izbor. Inače bi se u bazu moglo upisati bilo
        // šta — a i predlog je mogao da zastari dok je stranica stajala
        // otvorena.
        foreach ($plan['strong'] as $strong) {
            $wanted[] = [
                'source_id'  => (int) $strong['source']['id'],
                'ci_uuid'    => (string) $strong['ci']['uuid'],
                'matched_by' => 'correlation',
                'confirmed'  => true,
            ];
        }

        foreach ($plan['weak'] as $weak) {
            $ref = $weak['source']['id'] . ':' . $weak['ci']['uuid'];

            if (!in_array($ref, $chosen, true)) {
                continue;
            }

            $wanted[] = [
                'source_id'  => (int) $weak['source']['id'],
                'ci_uuid'    => (string) $weak['ci']['uuid'],
                'matched_by' => (string) $weak['matched_by'],
                'confirmed'  => true,
            ];
        }

        if ($wanted === []) {
            $this->flash->error('Ništa nije izabrano za povezivanje.');
            header('Location: /assetops/match?integration=' . $integration->id);

            return;
        }

        $result = $this->links->create($integration->id, $sourceKind, $wanted);

        $message = "Povezano {$result['created']}.";

        if ($result['skipped'] !== []) {
            $message .= ' Preskočeno ' . count($result['skipped'])
                      . ' — objekat ili CI je u međuvremenu već povezan.';
        }

        $this->flash->success($message);
        header('Location: /assetops/match?integration=' . $integration->id);
    }

    /**
     * Raskini jednu vezu.
     */
    public function unlink(string $id): void
    {
        $integration = $this->requireAssetOps($id);

        if ($integration === null) {
            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /assetops/match');

            return;
        }

        $sourceKind = (string) ($_POST['source_kind'] ?? '');
        $sourceId   = (int) ($_POST['source_id'] ?? 0);

        if (!isset(FieldCatalog::ENTITY[$sourceKind]) || $sourceId === 0) {
            $this->flash->error('Nepoznata veza.');
            header('Location: /assetops/match?integration=' . $integration->id);

            return;
        }

        $this->flash->success(
            $this->links->remove($integration->id, $sourceKind, $sourceId)
                ? 'Veza je raskinuta. CI u AssetOPS-u nije diran.'
                : 'Ta veza više ne postoji.'
        );

        header('Location: /assetops/match?integration=' . $integration->id);
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
     * Spisak ciljnih polja za jednu vrstu izvora.
     *
     * Sam racun je u TargetCatalog, da moze da se proveri bez kontrolera —
     * sta se nudi u mapiranju odlucuje i sta se upisuje, pa to ne sme da
     * zivi samo ovde.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(int $integrationId, string $sourceKind): array
    {
        return TargetCatalog::forSourceKind($this->repo, $integrationId, $sourceKind);
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
