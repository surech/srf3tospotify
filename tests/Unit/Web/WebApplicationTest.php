<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Application\Import\ImportLocked;
use App\Infrastructure\Spotify\SpotifyNotAuthorized;
use App\Infrastructure\Spotify\SpotifyRateLimited;
use App\Web\CsrfGuard;
use App\Web\OAuthState;
use App\Web\OwnerAuthentication;
use App\Web\PageRenderer;
use App\Web\Request;
use App\Web\Response;
use App\Web\WebApplication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fakes\ArraySessionStore;
use Tests\Fakes\FakeWebOperations;

#[CoversClass(WebApplication::class)]
#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(PageRenderer::class)]
final class WebApplicationTest extends TestCase
{
    private ArraySessionStore $session;
    private FakeWebOperations $operations;
    private WebApplication $application;
    private CsrfGuard $csrf;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore();
        $this->operations = new FakeWebOperations();
        $this->csrf = new CsrfGuard($this->session);
        $this->application = new WebApplication(
            $this->operations,
            new OwnerAuthentication($this->session, password_hash('correct-password', PASSWORD_DEFAULT)),
            $this->csrf,
            new OAuthState($this->session),
            $this->session,
            new PageRenderer(\dirname(__DIR__, 3) . '/templates'),
            'https://app.example',
            'cron-secret',
        );
    }

    public function testAnonymousDashboardRedirectsToLogin(): void
    {
        $response = $this->application->handle(new Request('GET', '/'));

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    public function testLoginRequiresCsrfAndRotatesSession(): void
    {
        $page = $this->application->handle(new Request('GET', '/login'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Anmelden', $page->body);

        $forbidden = $this->application->handle(new Request('POST', '/login', form: [
            '_csrf' => 'wrong',
            'password' => 'correct-password',
        ]));
        self::assertSame(403, $forbidden->status);

        $response = $this->application->handle(new Request('POST', '/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'correct-password',
        ]));
        self::assertSame(303, $response->status);
        self::assertSame('/', $response->headers['Location']);
        self::assertSame(1, $this->session->regenerations);
    }

    public function testProtectedActionRejectsInvalidCsrf(): void
    {
        $this->login();

        $response = $this->application->handle(new Request('POST', '/actions/import', form: [
            '_csrf' => 'wrong',
            'from_date' => '2026-08-24',
            'to_date' => '2026-08-24',
        ]));

        self::assertSame(403, $response->status);
        self::assertSame([], $this->operations->imports);
    }

    public function testBearerCronRejectsMissingTokenAndExecutesWithToken(): void
    {
        $unauthorized = $this->application->handle(new Request('POST', '/internal/cron/import'));
        self::assertSame(401, $unauthorized->status);

        $authorized = $this->application->handle(new Request(
            'POST',
            '/internal/cron/sync',
            headers: ['authorization' => 'Bearer cron-secret'],
        ));

        self::assertSame(200, $authorized->status);
        self::assertSame(['http-cron'], $this->operations->synchronizations);
    }

    public function testSyncWithoutSpotifyAuthorizationRedirectsToOAuth(): void
    {
        $this->login();
        $this->operations->synchronizeException = new SpotifyNotAuthorized('Spotify authorization is required.');

        $response = $this->application->handle(new Request('POST', '/actions/sync', form: [
            '_csrf' => $this->csrf->token(),
        ]));

        self::assertSame(303, $response->status);
        self::assertSame('/spotify/authorize', $response->headers['Location']);

        $internal = $this->application->handle(new Request(
            'POST',
            '/internal/cron/sync',
            headers: ['authorization' => 'Bearer cron-secret'],
        ));
        self::assertSame(409, $internal->status);
        self::assertStringContainsString('SPOTIFY_NOT_AUTHORIZED', $internal->body);
    }

    public function testOAuthCallbackValidatesOneTimeState(): void
    {
        $this->login();
        $authorization = $this->application->handle(new Request('GET', '/spotify/authorize'));
        self::assertSame(302, $authorization->status);
        $issuedState = $this->operations->authorizations[0]['state'];
        self::assertSame('https://app.example/spotify/callback', $this->operations->authorizations[0]['redirect_uri']);

        $invalid = $this->application->handle(new Request('GET', '/spotify/callback', [
            'state' => 'wrong',
            'code' => 'code-value',
        ]));
        self::assertSame(403, $invalid->status);
        self::assertSame([], $this->operations->exchanges);

        $this->application->handle(new Request('GET', '/spotify/authorize'));
        $freshState = $this->operations->authorizations[1]['state'];
        $callback = $this->application->handle(new Request('GET', '/spotify/callback', [
            'state' => $freshState,
            'code' => 'code-value',
        ]));
        self::assertSame(303, $callback->status);
        self::assertSame('code-value', $this->operations->exchanges[0]['code']);

        $replay = $this->application->handle(new Request('GET', '/spotify/callback', [
            'state' => $freshState,
            'code' => 'code-value',
        ]));
        self::assertSame(403, $replay->status);
        self::assertSame(1, \count($this->operations->exchanges));
    }

    public function testAuthenticatedDashboardActionsMatchesAndLogout(): void
    {
        $this->login();

        $loginPage = $this->application->handle(new Request('GET', '/login'));
        self::assertSame(303, $loginPage->status);

        $dashboard = $this->application->handle(new Request('GET', '/'));
        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('Meistgespielte Songs', $dashboard->body);

        $token = $this->csrf->token();
        $import = $this->application->handle(new Request('POST', '/actions/import', form: [
            '_csrf' => $token,
            'from_date' => '2026-08-24',
            'to_date' => '2026-08-24',
        ]));
        self::assertSame(303, $import->status);
        self::assertSame('manual', $this->operations->imports[0]['trigger']);
        self::assertStringContainsString(
            'Import abgeschlossen',
            $this->application->handle(new Request('GET', '/'))->body,
        );

        self::assertSame(303, $this->application->handle(new Request('POST', '/actions/sync', form: [
            '_csrf' => $token,
        ]))->status);
        self::assertSame(['manual'], $this->operations->synchronizations);
        self::assertStringContainsString(
            'Spotify synchronisiert: 2 Playlists, 4 Tracks.',
            $this->application->handle(new Request('GET', '/'))->body,
        );

        self::assertSame(303, $this->application->handle(new Request('POST', '/matches/42', form: [
            '_csrf' => $token,
            'track' => 'spotify:track:test',
        ]))->status);
        self::assertSame([['song_id' => 42, 'track' => 'spotify:track:test']], $this->operations->selectedMatches);

        self::assertSame(303, $this->application->handle(new Request('POST', '/matches/43', form: [
            '_csrf' => $token,
            'action' => 'reject',
        ]))->status);
        self::assertSame([43], $this->operations->rejectedMatches);

        $invalidLogout = $this->application->handle(new Request('POST', '/logout', form: ['_csrf' => 'wrong']));
        self::assertSame(403, $invalidLogout->status);
        $logout = $this->application->handle(new Request('POST', '/logout', form: ['_csrf' => $token]));
        self::assertSame(303, $logout->status);
        self::assertTrue($this->session->destroyed);
    }

    public function testFailedLoginHealthAndUnknownRoute(): void
    {
        $failed = $this->application->handle(new Request('POST', '/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'wrong-password',
        ]));
        self::assertSame(401, $failed->status);
        self::assertStringContainsString('Passwort nicht korrekt', $failed->body);

        self::assertSame(200, $this->application->handle(new Request('GET', '/health'))->status);
        $this->login();
        self::assertSame(404, $this->application->handle(new Request('GET', '/missing'))->status);
    }

    public function testCronMethodImportAndUnknownRoute(): void
    {
        $headers = ['authorization' => 'Bearer cron-secret'];
        self::assertSame(405, $this->application->handle(new Request('GET', '/internal/cron/import', headers: $headers))->status);

        $import = $this->application->handle(new Request('POST', '/internal/cron/import', headers: $headers));
        self::assertSame(200, $import->status);
        self::assertSame('http-cron', $this->operations->imports[0]['trigger']);

        $missing = $this->application->handle(new Request('POST', '/internal/cron/missing', headers: $headers));
        self::assertSame(404, $missing->status);

        $migration = $this->application->handle(new Request(
            'POST',
            '/internal/maintenance/migrate',
            headers: $headers,
        ));
        self::assertSame(200, $migration->status);
        self::assertSame(1, $this->operations->migrations);
    }

    public function testOAuthDenialConsumesValidState(): void
    {
        $this->login();
        $this->application->handle(new Request('GET', '/spotify/authorize'));
        $state = $this->operations->authorizations[0]['state'];

        $response = $this->application->handle(new Request('GET', '/spotify/callback', [
            'state' => $state,
            'error' => 'access_denied',
        ]));

        self::assertSame(400, $response->status);
        self::assertSame([], $this->operations->exchanges);
    }

    public function testMapsValidationLockRateLimitAndUnexpectedErrors(): void
    {
        $this->login();
        $token = $this->csrf->token();

        $this->operations->importException = new \InvalidArgumentException('invalid');
        self::assertSame(422, $this->application->handle(new Request('POST', '/actions/import', form: [
            '_csrf' => $token,
        ]))->status);

        $this->operations->importException = new ImportLocked('locked');
        self::assertSame(409, $this->application->handle(new Request('POST', '/actions/import', form: [
            '_csrf' => $token,
        ]))->status);

        $this->operations->synchronizeException = new SpotifyRateLimited(17);
        $limited = $this->application->handle(new Request('POST', '/actions/sync', form: ['_csrf' => $token]));
        self::assertSame(503, $limited->status);
        self::assertSame('17', $limited->headers['Retry-After']);

        $this->operations->synchronizeException = new RuntimeException('Spotify <diagnostic> detail');
        $errorLog = sys_get_temp_dir() . '/srf3spotify-web-error-' . bin2hex(random_bytes(8)) . '.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        try {
            $unexpected = $this->application->handle(new Request('POST', '/actions/sync', form: [
                '_csrf' => $token,
            ]));
            self::assertSame(500, $unexpected->status);
            self::assertStringContainsString('Technische Details', $unexpected->body);
            self::assertStringContainsString('RuntimeException', $unexpected->body);
            self::assertStringContainsString('Spotify &lt;diagnostic&gt; detail', $unexpected->body);
            self::assertStringNotContainsString('Spotify <diagnostic> detail', $unexpected->body);
            self::assertStringContainsString('POST /actions/sync', $unexpected->body);
            self::assertStringNotContainsString('WebApplication.php', $unexpected->body);
            self::assertSame(1, preg_match(
                '~Fehler-ID</dt>\s*<dd><code>([0-9a-f-]{36})</code>~',
                $unexpected->body,
                $matches,
            ));
            $errorId = $matches[1] ?? '';
            self::assertNotSame('', $errorId);
            $logContents = file_get_contents($errorLog);
            self::assertIsString($logContents);
            self::assertStringContainsString('[' . $errorId . '] POST /actions/sync', $logContents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }
            @unlink($errorLog);
        }
    }

    private function login(): void
    {
        $response = $this->application->handle(new Request('POST', '/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'correct-password',
        ]));
        self::assertSame(303, $response->status);
    }
}
