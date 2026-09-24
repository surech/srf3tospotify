<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Web\NativeSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeSessionStore::class)]
final class NativeSessionStoreTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStartsAdminScopedSessionLazily(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status());
        $session = new NativeSessionStore(true);
        self::assertSame(PHP_SESSION_NONE, session_status());

        $session->set('key', 'value');

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame('value', $session->get('key'));
        self::assertSame('srf3spotify_admin_session', session_name());
        $parameters = session_get_cookie_params();
        self::assertSame('/admin', $parameters['path']);
        self::assertTrue($parameters['secure']);
        self::assertTrue($parameters['httponly']);
        self::assertSame('Lax', $parameters['samesite']);

        $session->destroy();
        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
