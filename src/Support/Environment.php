<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Environment
{
    public static function load(string $projectRoot): void
    {
        $path = rtrim($projectRoot, '/') . '/.env';
        if (!is_file($path)) {
            return;
        }

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($values === false) {
            throw new RuntimeException('Unable to parse .env configuration.');
        }

        foreach ($values as $key => $value) {
            if (!\is_string($key) || (!\is_string($value) && !is_numeric($value))) {
                continue;
            }

            if (getenv($key) !== false) {
                continue;
            }

            $stringValue = (string) $value;
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
        }
    }
}
