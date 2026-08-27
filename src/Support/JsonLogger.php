<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class JsonLogger
{
    public function __construct(private readonly string $path) {}

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        $record = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'level' => $level,
            'event' => $event,
            'context' => $this->redact($context),
        ];
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            error_log($json);

            return;
        }

        if (@file_put_contents($this->path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($json);
        }
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|authorization/i', $key) === 1) {
                $values[$key] = '[REDACTED]';
                continue;
            }

            if (\is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
