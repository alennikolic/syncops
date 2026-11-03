<?php
// src/Web/View.php
//
// Renderovanje PHP sablona. Sablon se izvrsava u izolovanoj funkciji, pa
// nema pristup promenljivim kontrolera osim onome sto mu je prosledjeno.
//
// Sablon dobija i $e — funkciju za escape. Sve sto dolazi iz baze ili iz
// forme mora kroz nju.

declare(strict_types=1);

namespace SyncOps\Web;

use RuntimeException;
use Throwable;

final class View
{
    /** @var array<string, mixed> Vrednosti dostupne svakom sablonu */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Renderuj sablon i umotaj ga u layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): void
    {
        $content = $this->capture($template, $data);

        echo $this->capture('layout', $data + ['content' => $content]);
    }

    /**
     * Renderuj sablon bez layout-a (npr. stranica prijave ima svoj okvir).
     *
     * @param array<string, mixed> $data
     */
    public function renderBare(string $template, array $data = []): void
    {
        echo $this->capture($template, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $file = $this->dir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Sablon ne postoji: {$template}");
        }

        $data['e'] = self::escaper();

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);

            ob_start();

            try {
                require $__file;
            } catch (Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $data + $this->shared);
    }

    /**
     * @return callable(mixed): string
     */
    public static function escaper(): callable
    {
        return static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
