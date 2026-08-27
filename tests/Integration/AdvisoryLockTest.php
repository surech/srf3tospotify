<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Support\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdvisoryLock::class)]
final class AdvisoryLockTest extends TestCase
{
    public function testSecondConnectionCannotAcquireHeldLock(): void
    {
        $factory = new ConnectionFactory(Config::fromEnvironment());
        $first = new AdvisoryLock($factory->create());
        $second = new AdvisoryLock($factory->create());
        $name = 'srf3tospotify:test:' . bin2hex(random_bytes(8));

        self::assertTrue($first->acquire($name));
        try {
            self::assertFalse($second->acquire($name));
        } finally {
            $first->release($name);
        }

        self::assertTrue($second->acquire($name));
        $second->release($name);
    }
}
