<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Web\CsrfGuard;
use App\Web\OAuthState;
use App\Web\OwnerAuthentication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\ArraySessionStore;

#[CoversClass(OwnerAuthentication::class)]
#[CoversClass(CsrfGuard::class)]
#[CoversClass(OAuthState::class)]
final class SecurityTest extends TestCase
{
    public function testLoginRegeneratesSessionAndLogoutDestroysIt(): void
    {
        $session = new ArraySessionStore();
        $authentication = new OwnerAuthentication($session, password_hash('correct', PASSWORD_DEFAULT));

        self::assertFalse($authentication->login('wrong'));
        self::assertFalse($authentication->authenticated());
        self::assertTrue($authentication->login('correct'));
        self::assertTrue($authentication->authenticated());
        self::assertSame(1, $session->regenerations);

        $authentication->logout();
        self::assertTrue($session->destroyed);
        self::assertFalse($authentication->authenticated());
    }

    public function testCsrfTokenPersistsAndRotates(): void
    {
        $guard = new CsrfGuard(new ArraySessionStore());
        $first = $guard->token();

        self::assertSame($first, $guard->token());
        self::assertTrue($guard->valid($first));
        self::assertFalse($guard->valid('wrong'));

        $second = $guard->rotate();
        self::assertNotSame($first, $second);
        self::assertFalse($guard->valid($first));
        self::assertTrue($guard->valid($second));
    }

    public function testOAuthStateCanOnlyBeConsumedOnce(): void
    {
        $state = new OAuthState(new ArraySessionStore());
        $value = $state->issue();

        self::assertFalse($state->consume('wrong'));
        self::assertFalse($state->consume($value));

        $fresh = $state->issue();
        self::assertTrue($state->consume($fresh));
        self::assertFalse($state->consume($fresh));
    }
}
