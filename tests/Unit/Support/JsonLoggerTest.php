<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JsonLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonLogger::class)]
final class JsonLoggerTest extends TestCase
{
    public function testWritesJsonAndRedactsSecrets(): void
    {
        $path = sys_get_temp_dir() . '/srf3spotify-' . bin2hex(random_bytes(8)) . '.log';
        $logger = new JsonLogger($path);

        $logger->info('test.event', [
            'correlation_id' => 'run-1',
            'access_token' => 'sensitive',
            'nested' => ['client_secret' => 'also-sensitive'],
        ]);

        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('test.event', $record['event']);
        self::assertSame('run-1', $record['context']['correlation_id']);
        self::assertSame('[REDACTED]', $record['context']['access_token']);
        self::assertSame('[REDACTED]', $record['context']['nested']['client_secret']);
        unlink($path);
    }

    public function testErrorCreatesMissingDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/srf3spotify-' . bin2hex(random_bytes(8));
        $path = $directory . '/nested/application.log';

        (new JsonLogger($path))->error('test.failed');

        self::assertFileExists($path);
        self::assertStringContainsString('test.failed', (string) file_get_contents($path));
        unlink($path);
        rmdir(\dirname($path));
        rmdir($directory);
    }

    public function testUnwritableFileFallsBackWithoutFailingOperation(): void
    {
        $fallbackPath = sys_get_temp_dir() . '/srf3spotify-fallback-' . bin2hex(random_bytes(8)) . '.log';
        $previousErrorLog = ini_set('error_log', $fallbackPath);

        try {
            (new JsonLogger('/proc/srf3spotify/application.log'))->info('fallback.event');

            self::assertStringContainsString('fallback.event', (string) file_get_contents($fallbackPath));
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }
            @unlink($fallbackPath);
        }
    }
}
