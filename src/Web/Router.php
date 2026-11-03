<?php
// src/Web/Router.php
//
// Minimalni ruter. Putanje se opisuju sablonom sa {imenovanim} delovima,
// npr. /integrations/{id}/state. Vrednosti se prosledjuju handleru redom.

declare(strict_types=1);

namespace SyncOps\Web;

final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Pronadji rutu i pozovi handler. Vraca false ako putanja ne postoji.
     */
    public function dispatch(string $method, string $path): bool
    {
        // Zavrsna kosa crta se ignorise, osim za koren.
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $pathExists = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathExists = true;

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            ($route['handler'])(...$matches);

            return true;
        }

        // Putanja postoji ali ne za ovaj HTTP metod.
        if ($pathExists) {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Metod nije dozvoljen za ovu putanju.';

            return true;
        }

        return false;
    }
}
