<?php
// src/Web/Controller/AuthController.php

declare(strict_types=1);

namespace SyncOps\Web\Controller;

use SyncOps\Web\Auth;
use SyncOps\Web\Flash;
use SyncOps\Web\View;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Flash $flash,
    ) {
    }

    public function loginForm(): void
    {
        if ($this->auth->check()) {
            header('Location: /');

            return;
        }

        $this->view->renderBare('login', [
            'title'      => 'Prijava',
            'configured' => $this->auth->isConfigured(),
            'csrf'       => $this->auth->csrfToken(),
            'flash'      => $this->flash->take(),
        ]);
    }

    public function login(): void
    {
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->flash->error('Sesija je istekla. Pokusaj ponovo.');
            header('Location: /login');

            return;
        }

        if ($this->auth->attempt((string) ($_POST['password'] ?? ''))) {
            header('Location: /');

            return;
        }

        // Kratka pauza usporava pogadjanje lozinke grubom silom.
        usleep(400_000);

        $this->flash->error('Pogrešna lozinka.');
        header('Location: /login');
    }

    public function logout(): void
    {
        if ($this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->auth->logout();
        }

        header('Location: /login');
    }
}
