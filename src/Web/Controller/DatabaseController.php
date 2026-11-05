<?php
// src/Web/Controller/DatabaseController.php
//
// Pregled sopstvene baze: koje tabele postoje, kakve su im kolone i sta
// tacno stoji u redovima.
//
// Stranica je dijagnosticka. Za razliku od ostatka GUI-ja, ovde se nista ne
// prevodi, ne zaokruzuje i ne pretvara u GiB — prikazuje se vrednost kakva
// je u koloni. To je i poenta: kad se maper i prikaz ne slazu, ovde se vidi
// koja je strana u pravu.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Storage\Maintenance;
use SyncOps\Storage\SchemaQuery;
use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;

final class DatabaseController
{
    public function __construct(
        private readonly SchemaQuery $schema,
        private readonly View $view,
        private readonly Maintenance $maintenance,
        private readonly Auth $auth,
        private readonly Flash $flash,
    ) {
    }

    public function index(): void
    {
        $this->view->render('database/index', [
            'title'    => 'Baza',
            'nav'      => 'database',
            'database' => $this->schema->databaseName(),
            'tables'   => $this->schema->tables(),
        ]);
    }

    public function table(string $table): void
    {
        if (!$this->schema->exists($table)) {
            $this->notFound();

            return;
        }

        $this->view->render('database/table', [
            'title'       => $table,
            'nav'         => 'database',
            'table'       => $table,
            'columns'     => $this->schema->columns($table),
            'indexes'     => $this->schema->indexes($table),
            'foreignKeys' => $this->schema->foreignKeys($table),
            'primaryKey'  => $this->schema->primaryKey($table),
            'result'      => $this->schema->rows($table, (int) ($_GET['page'] ?? 1)),
        ]);
    }

    public function row(string $table, string $id): void
    {
        if (!$this->schema->exists($table)) {
            $this->notFound();

            return;
        }

        $row = $this->schema->row($table, $id);

        if ($row === null) {
            $this->notFound('/db/' . rawurlencode($table));

            return;
        }

        $this->view->render('database/row', [
            'title'      => $table . ' #' . $id,
            'nav'        => 'database',
            'table'      => $table,
            'id'         => $id,
            'primaryKey' => $this->schema->primaryKey($table),
            'columns'    => $this->schema->columns($table),
            'row'        => $row,
        ]);
    }

    /**
     * Potvrda pre brisanja. Zasebna stranica, da se tacni brojevi vide pre
     * nego sto se nesto obrise.
     */
    public function resetForm(): void
    {
        $this->view->render('database/reset', [
            'title'     => 'Brisanje podataka',
            'nav'       => 'database',
            'clearable' => $this->maintenance->clearable(),
            'preserved' => Maintenance::PRESERVED,
            'busy'      => $this->maintenance->hasRunningJob(),
            'csrf'      => $this->auth->csrfToken(),
        ]);
    }

    public function reset(): void
    {
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokušaj ponovo.');
            header('Location: /db/reset');

            return;
        }

        // Druga provera, ne samo ona na stranici potvrde: posao je mogao da
        // krene izmedju prikaza i klika.
        if ($this->maintenance->hasRunningJob()) {
            $this->flash->error('Prikupljanje je u toku. Sačekaj da se završi pa pokušaj ponovo.');
            header('Location: /db/reset');

            return;
        }

        $cleared = $this->maintenance->clearCollected();
        $rows    = array_sum($cleared);

        $this->flash->success(sprintf(
            'Obrisano %s redova iz %d tabela. Integracije su ostale.',
            number_format($rows, 0, ',', '.'),
            count($cleared)
        ));

        header('Location: /db');
    }

    private function notFound(string $back = '/db'): void
    {
        http_response_code(404);
        header('Location: ' . $back);
    }
}
