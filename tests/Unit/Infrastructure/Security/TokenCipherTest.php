<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\TokenCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TokenCipher::class)]
final class TokenCipherTest extends TestCase
{
    public function testRoundTripUsesRandomAuthenticatedEnvelope(): void
    {
        $cipher = new TokenCipher(str_repeat('k', 32));

        $first = $cipher->encrypt('refresh-token');
        $second = $cipher->encrypt('refresh-token');

        self::assertNotSame($first, $second);
        self::assertSame('refresh-token', $cipher->decrypt($first));
        self::assertSame('refresh-token', $cipher->decrypt($second));
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $cipher = new TokenCipher(str_repeat('k', 32));
        $envelope = $cipher->encrypt('refresh-token');
        $envelope[29] = \chr(\ord($envelope[29]) ^ 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decrypt');

        $cipher->decrypt($envelope);
    }
}
