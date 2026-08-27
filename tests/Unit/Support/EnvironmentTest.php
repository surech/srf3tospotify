<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testLoadsFileWithoutOverwritingExistingEnvironment(): void
    {
        $directory = sys_get_temp_dir() . '/srf3spotify-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents(
            $directory . '/.env',
            "SRF_TEST_FROM_FILE=loaded\nSRF_TEST_EXISTING=file-value\n",
        );
        putenv('SRF_TEST_FROM_FILE');
        putenv('SRF_TEST_EXISTING=process-value');

        Environment::load($directory);

        self::assertSame('loaded', getenv('SRF_TEST_FROM_FILE'));
        self::assertSame('process-value', getenv('SRF_TEST_EXISTING'));

        putenv('SRF_TEST_FROM_FILE');
        putenv('SRF_TEST_EXISTING');
        unlink($directory . '/.env');
        rmdir($directory);
    }

    public function testMissingFileIsIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        Environment::load(sys_get_temp_dir() . '/missing-srf3spotify-' . bin2hex(random_bytes(8)));
    }
}
