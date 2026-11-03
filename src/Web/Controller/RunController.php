<?php
// src/Web/Controller/RunController.php
//
// Istorija discovery poslova.

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use PDO;
use SyncOps\Web\View;

final class RunController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly View $view,
    ) {
    }

    public function index(): void
    {
        $runs = $this->pdo->query(
            'SELECT r.*, i.name AS integration_name,
                    TIMESTAMPDIFF(SECOND, r.started_at, r.finished_at) AS duration_seconds
               FROM discovery_run r
               JOIN integration i ON i.id = r.integration_id
              ORDER BY r.id DESC
              LIMIT 100'
        )->fetchAll();

        $this->view->render('run/index', [
            'title' => 'Prikupljanja',
            'nav'   => 'runs',
            'runs'  => $runs,
        ]);
    }
}
