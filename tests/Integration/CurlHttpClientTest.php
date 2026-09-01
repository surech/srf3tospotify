<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Http\CurlHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CurlHttpClient::class)]
final class CurlHttpClientTest extends TestCase
{
    public function testCallsLocalHealthEndpoint(): void
    {
        $response = (new CurlHttpClient())->request('GET', 'http://localhost/health');

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/json', (string) $response->header('content-type'));
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains($payload['admin_password_hash']['source'], ['process_environment', '.env']);
        self::assertIsInt($payload['admin_password_hash']['length']);
        self::assertIsString($payload['admin_password_hash']['algorithm']);
        self::assertIsString($payload['admin_password_hash']['prefix']);
        self::assertIsString($payload['admin_password_hash']['suffix']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['admin_password_hash']['sha256']);
    }

    public function testRejectsEmptyMethod(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('method');

        (new CurlHttpClient())->request(' ', 'http://localhost/health');
    }
}
