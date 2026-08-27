<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Web\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    /** @var array<array-key, mixed> */
    private array $get;

    /** @var array<array-key, mixed> */
    private array $post;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
    }

    public function testBuildsRequestFromStringGlobalsOnly(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/actions/import?ignored=yes',
            'HTTP_AUTHORIZATION' => 'Bearer token',
            'HTTP_INVALID' => ['ignored'],
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        $_GET = ['date' => '2026-08-24', 'nested' => ['ignored']];
        $_POST = ['from_date' => '2026-08-24', 1 => 'ignored-key'];

        $request = Request::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame('/actions/import', $request->path);
        self::assertSame(['date' => '2026-08-24'], $request->query);
        self::assertSame(['from_date' => '2026-08-24'], $request->form);
        self::assertSame('Bearer token', $request->header('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
        self::assertNull($request->header('missing'));
    }

    public function testDefaultsMissingMethodAndPath(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];

        $request = Request::fromGlobals();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
    }

    public function testReadsAuthorizationAfterApacheRewrite(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/internal/cron/import',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token',
        ];
        $_GET = [];
        $_POST = [];

        self::assertSame('Bearer redirected-token', Request::fromGlobals()->header('authorization'));
    }
}
