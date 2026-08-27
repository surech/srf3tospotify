<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final readonly class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values) {}

    public static function fromEnvironment(): self
    {
        $keys = [
            'APP_ENV',
            'APP_KEY',
            'APP_URL',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'ADMIN_PASSWORD_HASH',
            'CRON_TOKEN',
            'SRF_BASE_URL',
            'SRF_CHANNEL_ID',
            'SRF_PAGE_SIZE',
            'SPOTIFY_CLIENT_ID',
            'SPOTIFY_CLIENT_SECRET',
        ];

        $values = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $values[$key] = $value;
            }
        }

        return self::fromArray($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        $defaults = [
            'APP_ENV' => 'production',
            'DB_PORT' => '3306',
            'ADMIN_PASSWORD_HASH' => '',
            'CRON_TOKEN' => '',
            'SRF_BASE_URL' => 'https://il.srf.ch/integrationlayer/2.0/srf/songList/radio/byChannel',
            'SRF_CHANNEL_ID' => 'dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f',
            'SRF_PAGE_SIZE' => '500',
            'SPOTIFY_CLIENT_ID' => '',
            'SPOTIFY_CLIENT_SECRET' => '',
        ];
        $config = new self(array_merge($defaults, $values));

        foreach (['APP_KEY', 'APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            $config->required($key);
        }

        if (\strlen($config->required('APP_KEY')) < 32) {
            throw new InvalidArgumentException('APP_KEY must contain at least 32 characters.');
        }

        $port = $config->int('DB_PORT');
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('DB_PORT must be between 1 and 65535.');
        }

        return $config;
    }

    public function required(string $key): string
    {
        $value = trim($this->values[$key] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(\sprintf('Required configuration %s is missing.', $key));
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? (string) $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(\sprintf('Configuration %s must be an integer.', $key));
        }

        return (int) $value;
    }

    public function databaseDsn(): string
    {
        return \sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->required('DB_HOST'),
            $this->int('DB_PORT'),
            $this->required('DB_NAME'),
        );
    }

    public function isProduction(): bool
    {
        return $this->string('APP_ENV', 'production') === 'production';
    }
}
