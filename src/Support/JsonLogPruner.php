<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final readonly class JsonLogPruner
{
    public function __construct(private string $path) {}

    public function pruneBefore(DateTimeImmutable $cutoff): int
    {
        if (!is_file($this->path)) {
            return 0;
        }

        $source = fopen($this->path, 'rb');
        if ($source === false) {
            throw new RuntimeException('Unable to read application log.');
        }
        $temporaryPath = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        $target = fopen($temporaryPath, 'xb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Unable to create temporary application log.');
        }

        $removed = 0;
        try {
            while (($line = fgets($source)) !== false) {
                if ($this->isOlderThan($line, $cutoff)) {
                    ++$removed;
                    continue;
                }
                if (fwrite($target, $line) === false) {
                    throw new RuntimeException('Unable to write temporary application log.');
                }
            }
        } catch (Throwable $exception) {
            fclose($source);
            fclose($target);
            @unlink($temporaryPath);
            throw $exception;
        }

        fclose($source);
        if (!fflush($target)) {
            fclose($target);
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to flush temporary application log.');
        }
        fclose($target);
        if (!rename($temporaryPath, $this->path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to replace application log.');
        }

        return $removed;
    }

    private function isOlderThan(string $line, DateTimeImmutable $cutoff): bool
    {
        try {
            $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $timestamp = \is_array($record) ? ($record['timestamp'] ?? null) : null;

            return \is_string($timestamp) && new DateTimeImmutable($timestamp) < $cutoff;
        } catch (Throwable) {
            return false;
        }
    }
}
