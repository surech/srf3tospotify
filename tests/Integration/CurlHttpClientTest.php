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
        self::assertSame(['status' => 'ok'], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRejectsEmptyMethod(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('method');

        (new CurlHttpClient())->request(' ', 'http://localhost/health');
    }
}
