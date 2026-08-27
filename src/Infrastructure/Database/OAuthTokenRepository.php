<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Security\TokenCipher;
use App\Infrastructure\Spotify\OAuthTokenStore;
use App\Infrastructure\Spotify\TokenSet;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class OAuthTokenRepository implements OAuthTokenStore
{
    public function __construct(
        private PDO $connection,
        private TokenCipher $cipher,
    ) {}

    public function load(): ?TokenSet
    {
        $query = $this->connection->query(
            "SELECT access_token_ciphertext, refresh_token_ciphertext, access_token_expires_at
             FROM oauth_tokens WHERE provider = 'spotify'",
        );
        $row = $query === false ? false : $query->fetch();
        if ($row === false) {
            return null;
        }

        return new TokenSet(
            $this->cipher->decrypt((string) $row['access_token_ciphertext']),
            $this->cipher->decrypt((string) $row['refresh_token_ciphertext']),
            new DateTimeImmutable((string) $row['access_token_expires_at'], new DateTimeZone('UTC')),
        );
    }

    public function save(TokenSet $tokens): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO oauth_tokens (
                    provider, access_token_ciphertext, refresh_token_ciphertext, access_token_expires_at
                ) VALUES (
                    'spotify', :access_token, :refresh_token, :expires_at
                ) ON DUPLICATE KEY UPDATE
                    access_token_ciphertext = VALUES(access_token_ciphertext),
                    refresh_token_ciphertext = VALUES(refresh_token_ciphertext),
                    access_token_expires_at = VALUES(access_token_expires_at)
                SQL,
        );
        $query->execute([
            'access_token' => $this->cipher->encrypt($tokens->accessToken),
            'refresh_token' => $this->cipher->encrypt($tokens->refreshToken),
            'expires_at' => $tokens->expiresAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
