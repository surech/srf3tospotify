<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\OAuthTokenRepository;
use App\Infrastructure\Security\TokenCipher;
use App\Infrastructure\Spotify\TokenSet;
use App\Support\Config;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthTokenRepository::class)]
final class OAuthTokenRepositoryTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->connection->exec("DELETE FROM oauth_tokens WHERE provider = 'spotify'");
    }

    protected function tearDown(): void
    {
        $this->connection->exec("DELETE FROM oauth_tokens WHERE provider = 'spotify'");
    }

    public function testPersistsEncryptedTokensAndLoadsPlainValues(): void
    {
        $repository = new OAuthTokenRepository($this->connection, new TokenCipher(str_repeat('k', 32)));
        $tokens = new TokenSet('access-value', 'refresh-value', new DateTimeImmutable('+1 hour'));

        $repository->save($tokens);
        $loaded = $repository->load();

        self::assertNotNull($loaded);
        self::assertSame('access-value', $loaded->accessToken);
        self::assertSame('refresh-value', $loaded->refreshToken);
        $statement = $this->connection->query(
            "SELECT access_token_ciphertext FROM oauth_tokens WHERE provider = 'spotify'",
        );
        if ($statement === false) {
            self::fail('Unable to inspect OAuth token storage.');
        }
        $raw = $statement->fetchColumn();
        self::assertIsString($raw);
        self::assertStringNotContainsString('access-value', $raw);
    }
}
