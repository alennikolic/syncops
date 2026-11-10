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
use SyncOps\Source\AssetOps\AssetOpsClient;
use SyncOps\Source\AssetOps\AssetOpsException;
use SyncOps\Source\AssetOps\CiCollector;
use SyncOps\Source\AssetOps\ClientFactory;
use SyncOps\Source\AssetOps\ReferenceCollector;
use SyncOps\Storage\AssetOpsRepository;
use SyncOps\Storage\InventoryQuery;
use SyncOps\Storage\LinkRepository;
use SyncOps\Storage\SyncActionRepository;
use SyncOps\Sync\CiOverview;
use SyncOps\Sync\FieldCatalog;
use SyncOps\Sync\Matcher;
use SyncOps\Sync\Applier;
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
        private readonly SyncActionRepository $journal,
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
     * Zateceno stanje u AssetOPS-u: zateceni CI-jevi i referentni spiskovi.
     *
     * Jedna stranica za oboje: dugme „Prikupi sada" ionako puni i jedno i
     * drugo u istom prolazu, pa su dva ekrana bila dve polovine iste slike.
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
            'title'        => 'Zatečeno stanje',
            'vanished'     => $this->repo->staleReference($selected->id),
            'nav'          => 'assetops',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'panels'       => $this->ciPanels($selected),
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
     * Stara adresa CI stranice. Odeljak je sada deo /assetops.
     *
     * Ruta ostaje da sacuvani link ne bi zavrsio na 404; izbor integracije
     * se prenosi, pa se zatekne isti podatak.
     */
    public function ciRedirect(): void
    {
        $integration = (string) ($_GET['integration'] ?? '');

        header('Location: /assetops' . ($integration === '' ? '' : '?integration=' . urlencode($integration)), true, 301);
    }

    /**
     * Zateceni CI-jevi, po vrsti izvora — gornji odeljak stranice.
     *
     * Ima vrednost i pre ijednog upisa: vidi se koliko CI-jeva nosi nas kljuc
     * spajanja, koliko ih je mapiranog tipa, i gde se te dve stvari ne
     * poklapaju.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ciPanels(Integration $integration): array
    {
        $mapping = $this->repo->mapping($integration->id);
        $refs    = $this->repo->correlationRefs($integration->id);
        $stats   = $this->repo->ciStats($integration->id);

        $panels = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $rows     = $this->repo->ciByEntity($integration->id, $entity);
            $typeSlug = $mapping[$sourceKind]['type_slug'] ?? null;
            $typeList = $this->repo->options(
                $integration->id,
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
                'duplicates'  => $this->repo->ciDuplicateKeys($integration->id, $entity),
            ];
        }

        return $panels;
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

        $panels = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $panels[$sourceKind] = $this->planFor($selected->id, $sourceKind, $entity) + [
                'label'       => $sourceKind === FieldCatalog::KIND_VM ? 'Virtuelne mašine' : 'Hostovi',
                'source_kind' => $sourceKind,
                'labels'      => $this->targetLabels($selected->id, $sourceKind, $entity),
            ];
        }

        // Dnevnik ima svoju stranicu; ovde stoji samo koliko ih ima.
        $counts = $this->journal->counts($selected->id);

        $this->view->render('assetops/plan', [
            'title'        => 'Plan',
            'nav'          => 'assetops-plan',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'panels'       => $panels,
            'journalCount'  => array_sum($counts),
            'journalFailed' => $counts['failed'],
            'applied'      => $this->journal->hasApplied($selected->id),
            'csrf'         => $this->auth->csrfToken(),
        ]);
    }

    /**
     * Dnevnik upisa u AssetOPS — svoja stranica.
     *
     * Odvojen od plana namerno: plan je predlog koji se računa iznova i živi
     * samo na ekranu, a dnevnik je zapis o učinjenom koji ostaje. Dva
     * različita pitanja, pa i dva ekrana.
     */
    public function journal(): void
    {
        $selected = $this->selectedOrEmpty('assetops-journal');

        if ($selected === null) {
            return;
        }

        $filters = [
            'status' => (string) ($_GET['status'] ?? ''),
            'action' => (string) ($_GET['action'] ?? ''),
            'kind'   => (string) ($_GET['kind'] ?? ''),
            'q'      => (string) ($_GET['q'] ?? ''),
        ];

        $perPage = 100;
        $page    = max(1, (int) ($_GET['page'] ?? 1));

        $total = $this->journal->countMatching($selected->id, $filters);

        $this->view->render('assetops/journal', [
            'title'        => 'Dnevnik upisa',
            'nav'          => 'assetops-journal',
            'integrations' => $this->assetOpsIntegrations(),
            'integration'  => $selected,
            'entries'      => $this->journal->search($selected->id, $filters, $perPage, ($page - 1) * $perPage),
            'summary'      => $this->journal->summary($selected->id),
            'filters'      => $filters,
            'total'        => $total,
            'page'         => $page,
            'pages'        => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * Plan za jednu vrstu izvora, sa svime što je potrebno i za upis.
     *
     * Isti račun koristi i ekran i dugme za sinhronizaciju — plan se nikad
     * ne uzima iz obrasca nego se računa iznova. Inače bi se u AssetOPS
     * moglo poslati bilo šta, a i predlog je mogao da zastari dok je
     * stranica stajala otvorena.
     *
     * @return array<string, mixed>
     */
    private function planFor(int $integrationId, string $sourceKind, string $entity): array
    {
        $cis = [];

        foreach ($this->repo->ciByEntity($integrationId, $entity) as $ci) {
            $cis[(string) $ci['uuid']] = $ci;
        }

        $types = [];

        foreach ($this->repo->attributesForEntity($integrationId, $entity) as $attribute) {
            // Atribut kog više nema u AssetOPS-u se ne upisuje. Izostaje iz
            // spiska tipova, pa ga Planner odbija sa razlogom umesto da
            // pošalje vrednost u nepostojeće polje.
            if (($attribute['missing'] ?? false) === true) {
                continue;
            }

            $types[(string) $attribute['slug']] = (string) ($attribute['attribute_type'] ?? '');
        }

        $fieldMap = $this->repo->fieldMap($integrationId, $sourceKind);
        $typeSlug = $this->repo->mapping($integrationId)[$sourceKind]['type_slug'] ?? null;

        return Planner::plan(
            inventory: $this->inventoryFor($sourceKind),
            links: $this->links->bySourceKind($integrationId, $sourceKind),
            cis: $cis,
            fieldMap: $fieldMap,
            attributeTypes: $types,
            sourceKind: $sourceKind,
            typeSlug: $typeSlug,
        ) + [
            'field_map'  => $fieldMap,
            'types'      => $types,
            'type_slug'  => $typeSlug,
        ];
    }

    /**
     * Pokreni sinhronizaciju: izvrši plan za jednu vrstu izvora.
     *
     * Ovo je jedino mesto u aplikaciji koje nešto menja u AssetOPS-u.
     */
    public function applyPlan(string $id): void
    {
        $integration = $this->requireAssetOps($id);

        if ($integration === null) {
            return;
        }

        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /assetops/plan');

            return;
        }

        $sourceKind = (string) ($_POST['source_kind'] ?? '');

        if (!isset(FieldCatalog::ENTITY[$sourceKind])) {
            $this->flash->error('Nepoznata vrsta izvora.');
            header('Location: /assetops/plan');

            return;
        }

        $plan = $this->planFor($integration->id, $sourceKind, FieldCatalog::ENTITY[$sourceKind]);

        if ($plan['actions'] === []) {
            $this->flash->success('Nema šta da se upiše — plan je prazan.');
            header('Location: /assetops/plan?integration=' . $integration->id);

            return;
        }

        if ($plan['type_slug'] === null) {
            $this->flash->error('Tip CI-ja nije izabran u Mapiranju.');
            header('Location: /assetops/plan?integration=' . $integration->id);

            return;
        }

        try {
            $client  = $this->factory->make($integration, $this->integrations->password($integration->id));
            $applier = new Applier($client, $this->links, $this->journal);

            $allowance = $applier->allowance($integration->id);

            $result = $applier->apply(
                integrationId: $integration->id,
                sourceKind: $sourceKind,
                actions: $plan['actions'],
                fieldMap: $plan['field_map'],
                attributeTypes: $plan['types'],
                typeSlug: (string) $plan['type_slug'],
                limit: $allowance['limit'],
            );

            $poruka = $this->applySummary($result, $allowance['pilot']);

            // Zateceno stanje je upravo zastarelo — mi smo ga sami promenili.
            // Bez ovoga bi plan i dalje predlagao ono sto je vec upisano, pa
            // bi covek morao rucno na „Prikupi sada" pre sledeceg prolaza.
            //
            // Samo kada je nesto zaista otislo: prolaz u kom je sve
            // preskoceno ili palo nije promenio nista u AssetOPS-u.
            if ($result['created'] + $result['updated'] > 0) {
                try {
                    $this->collectCurrent($integration, $client);
                    $poruka .= ' Zatečeno stanje je osveženo.';
                } catch (Throwable $e) {
                    // Upis je prosao; neuspelo citanje ne sme da ga prikaze
                    // kao gresku. Posledica je samo da plan jos jednom
                    // predlaze ono sto je vec upisano.
                    error_log('SyncOPS osvezavanje posle upisa: ' . $e->getMessage());
                    $poruka .= ' Zatečeno stanje NIJE osveženo (' . $e->getMessage()
                        . ') — pokreni „Prikupi sada" pre sledećeg prolaza.';
                }
            }

            $this->flash->success($poruka);
        } catch (AssetOpsException $e) {
            $this->flash->error($e->getMessage());
        } catch (Throwable $e) {
            error_log('SyncOPS upis u AssetOPS: ' . $e->getMessage());
            $this->flash->error('Neočekivana greška: ' . $e->getMessage());
        }

        header('Location: /assetops/plan?integration=' . $integration->id);
    }

    /**
     * @param array{created: int, updated: int, skipped: int, failed: int,
     *              remaining: int, notes: list<string>} $result
     */
    private function applySummary(array $result, bool $pilot): string
    {
        $parts = [];

        foreach (['created' => 'kreirano', 'updated' => 'izmenjeno',
                  'skipped' => 'preskočeno', 'failed' => 'neuspelo'] as $key => $label) {
            if ($result[$key] > 0) {
                $parts[] = "{$label} {$result[$key]}";
            }
        }

        $message = $parts === [] ? 'Ništa nije poslato.' : ucfirst(implode(', ', $parts)) . '.';

        if ($pilot) {
            $message .= ' Ovo je bio prvi upis, pa je ograničen na ' . Applier::PILOT
                      . ' zapisa — pogledaj ih u AssetOPS-u pre ostatka.';
        }

        if ($result['remaining'] > 0) {
            $message .= ' Ostalo je još ' . $result['remaining'] . '; pokreni ponovo.';
        }

        return $message;
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
            $parts  = $this->collectCurrent($integration, $client);

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
     * Pročitaj zatečeno stanje iz AssetOPS-a i upiši ga.
     *
     * Isti posao koristi dugme „Prikupi sada" i osvežavanje posle upisa, pa
     * stoji na jednom mestu. Prima gotovog klijenta umesto da ga pravi: posle
     * upisa je sesija već otvorena, a nova bi bila još jedna prijava.
     *
     * @return list<string> Sažetak po vrsti, za poruku na ekranu
     */
    private function collectCurrent(Integration $integration, AssetOpsClient $client): array
    {
        $parts = [];

        foreach ($this->collector->collect($client) as $kind => $rows) {
            $stats   = $this->repo->storeKind($integration->id, $kind, $rows);
            $parts[] = sprintf(
                '%s %d%s',
                $this->label($kind),
                $stats['seen'],
                $stats['new'] > 0 ? " (novo {$stats['new']})" : ''
            );
        }

        // CI-jevi se citaju u istom prolazu. Referentni spiskovi su tek
        // vokabular; bez zatecenog stanja odredista nema s cim da se poredi,
        // pa dva dugmeta za dve polovine iste slike ne bi imala smisla.
        //
        // Kljuc spajanja se cita iz mapiranja PRE prikupljanja: tako se
        // vrednost kljuca izvlaci u kolonu odmah, a ne naknadnim prolaskom
        // kroz payload.
        $collected = $this->cis->collect(
            $client,
            $this->entityRefs($integration->id),
            $this->describeUuids($integration->id)
        );

        foreach ($collected as $entity => $rows) {
            $stats   = $this->repo->storeCi($integration->id, $entity, $rows);
            $parts[] = sprintf(
                '%s %d%s',
                $this->label($entity),
                $stats['seen'],
                $stats['new'] > 0 ? " (novo {$stats['new']})" : ''
            );
        }

        $failed = $this->cis->descriptionFailures();

        if ($failed > 0) {
            $parts[] = "opis nije pročitan za {$failed}";
        }

        return $parts;
    }

    /**
     * Kojim CI-jevima treba dopuniti opis iz rute pojedinacnog zapisa.
     *
     * Opis je jedino nativno polje koje ruta liste ne vraca, pa je jedino
     * zbog kog se poziva zapis po zapisu. Da to ne postane poziv za svaki
     * CI na svaki klik, spisak se seku dve kapije:
     *
     *   1. Opis mora da bude mapiran politikom kojoj zatecena vrednost
     *      stvarno treba. „Ne diraj" se ne racuna, a „pri kreiranju"
     *      Planner preskace pre citanja zatecenog stanja.
     *   2. CI mora da bude spojen sa redom iz inventara. Nespojeni se tek
     *      kreiraju — njihov zatecen opis nema s cim da se poredi.
     *
     * Ako opis nije mapiran, vraca se prazan spisak i nema ni jednog
     * dodatnog poziva.
     *
     * Ako spisak jednog dana naraste na stotine, granica je `updated_at_from=`
     * na ruti liste (radi, ali samo na nivou dana): tada se dopunjavaju samo
     * CI-jevi koje je neko dirao od proslog prolaza.
     *
     * @return array<string, array<string, true>> entitet => {uuid: true}
     */
    private function describeUuids(int $integrationId): array
    {
        /** Politike kod kojih Planner zatecenu vrednost zaista cita. */
        $trazi = ['if_empty', 'always'];

        $byEntity = [];

        foreach (FieldCatalog::ENTITY as $sourceKind => $entity) {
            $byEntity[$entity] = [];

            $rule = $this->repo->fieldMap($integrationId, $sourceKind)['native:description'] ?? null;

            if ($rule === null || !in_array((string) ($rule['write_policy'] ?? 'never'), $trazi, true)) {
                continue;
            }

            foreach ($this->links->bySourceKind($integrationId, $sourceKind) as $link) {
                $uuid = trim((string) ($link['ci_uuid'] ?? ''));

                if ($uuid !== '') {
                    $byEntity[$entity][$uuid] = true;
                }
            }
        }

        return $byEntity;
    }

    /**
     * Obriši referentne redove kojih više nema u AssetOPS-u.
     *
     * Red na koji još pokazuje mapiranje se zadržava — on je jedino mesto
     * na kom se vidi da podešavanje pokazuje u nešto čega nema.
     */
    public function cleanReference(string $id): void
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

        $result = $this->repo->removeStaleReference($integration->id);

        if ($result['removed'] === 0 && $result['kept'] === 0) {
            $this->flash->success('Nema šta da se čisti — svi redovi su bili u poslednjem prikupljanju.');
        } else {
            $this->flash->success(sprintf(
                'Obrisano %d.%s',
                $result['removed'],
                $result['kept'] > 0
                    ? sprintf(' Zadržano %d, jer na njih još pokazuje Mapiranje.', $result['kept'])
                    : ''
            ));
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
