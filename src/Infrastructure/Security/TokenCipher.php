<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use RuntimeException;

final readonly class TokenCipher
{
    private string $key;

    public function __construct(string $applicationKey)
    {
        if (\strlen($applicationKey) < 32) {
            throw new RuntimeException('Token encryption key must contain at least 32 characters.');
        }
        $this->key = hash('sha256', $applicationKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );
        if ($ciphertext === false || \strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt OAuth token.');
        }

        return "\x01" . $nonce . $tag . $ciphertext;
    }

    public function decrypt(string $envelope): string
    {
        if (\strlen($envelope) < 30 || $envelope[0] !== "\x01") {
            throw new RuntimeException('OAuth token envelope is invalid.');
        }
        $nonce = substr($envelope, 1, 12);
        $tag = substr($envelope, 13, 16);
        $ciphertext = substr($envelope, 29);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt OAuth token.');
        }

        return $plaintext;
    }
}
