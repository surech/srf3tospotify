<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testBuildsDatabaseDsn(): void
    {
        $config = Config::fromArray($this->validValues());

        self::assertSame(
            'mysql:host=database;port=3306;dbname=radio;charset=utf8mb4',
            $config->databaseDsn(),
        );
    }

    public function testRejectsMissingRequiredValue(): void
    {
        $values = $this->validValues();
        unset($values['DB_PASSWORD']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_PASSWORD');

        Config::fromArray($values);
    }

    public function testRejectsShortApplicationKey(): void
    {
        $values = $this->validValues();
        $values['APP_KEY'] = 'short';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('32 characters');

        Config::fromArray($values);
    }

    public function testRejectsInvalidDatabasePort(): void
    {
        $values = $this->validValues();
        $values['DB_PORT'] = 'not-a-port';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an integer');

        Config::fromArray($values);
    }

    public function testRejectsOutOfRangeDatabasePort(): void
    {
        $values = $this->validValues();
        $values['DB_PORT'] = '70000';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 65535');

        Config::fromArray($values);
    }

    public function testProvidesOptionalDefaults(): void
    {
        $values = $this->validValues();
        unset($values['APP_ENV']);

        $config = Config::fromArray($values);

        self::assertTrue($config->isProduction());
        self::assertSame('', $config->string('SPOTIFY_CLIENT_ID'));
        self::assertSame('fallback', $config->string('UNKNOWN', 'fallback'));
        self::assertSame(12, $config->int('UNKNOWN', 12));
    }

    public function testLoadsProcessEnvironment(): void
    {
        $values = $this->validValues();
        $values['APP_URL'] = 'https://radio.example';

        $originalValues = [];
        foreach ($values as $key => $value) {
            $originalValues[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        try {
            $config = Config::fromEnvironment();

            self::assertSame('https://radio.example', $config->required('APP_URL'));
            self::assertFalse($config->isProduction());
        } finally {
            foreach ($originalValues as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    /** @return array<string, string> */
    private function validValues(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_KEY' => str_repeat('x', 32),
            'APP_URL' => 'http://localhost',
            'DB_HOST' => 'database',
            'DB_PORT' => '3306',
            'DB_NAME' => 'radio',
            'DB_USER' => 'radio',
            'DB_PASSWORD' => 'secret',
        ];
    }
}
