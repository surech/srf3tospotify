<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JsonLogPruner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonLogPruner::class)]
final class JsonLogPrunerTest extends TestCase
{
    public function testRemovesOnlyOldValidRecords(): void
    {
        $path = sys_get_temp_dir() . '/srf3spotify-prune-' . bin2hex(random_bytes(8)) . '.log';
        file_put_contents($path, implode("\n", [
            '{"timestamp":"2026-01-01T00:00:00+00:00","event":"old"}',
            '{"timestamp":"2026-08-01T00:00:00+00:00","event":"current"}',
            'invalid-line',
            '',
        ]));

        $removed = (new JsonLogPruner($path))->pruneBefore(new DateTimeImmutable('2026-07-01T00:00:00Z'));
        $content = (string) file_get_contents($path);

        self::assertSame(1, $removed);
        self::assertStringNotContainsString('"old"', $content);
        self::assertStringContainsString('"current"', $content);
        self::assertStringContainsString('invalid-line', $content);
        unlink($path);
    }

    public function testMissingLogNeedsNoCleanup(): void
    {
        $path = sys_get_temp_dir() . '/srf3spotify-missing-' . bin2hex(random_bytes(8)) . '.log';

        self::assertSame(0, (new JsonLogPruner($path))->pruneBefore(new DateTimeImmutable()));
    }
}
