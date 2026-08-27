<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final readonly class PageRenderer
{
    public function __construct(private string $templateDirectory) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = rtrim($this->templateDirectory, '/') . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Web template not found.');
        }
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        $output = ob_get_clean();
        if ($output === false) {
            throw new RuntimeException('Unable to render web template.');
        }

        return $output;
    }
}
