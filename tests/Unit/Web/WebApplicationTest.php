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
use Tests\Fakes\ArrayLoginRateLimiter;
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
    private ArrayLoginRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore();
        $this->operations = new FakeWebOperations();
        $this->csrf = new CsrfGuard($this->session);
        $this->rateLimiter = new ArrayLoginRateLimiter();
        $this->application = new WebApplication(
            $this->operations,
            new OwnerAuthentication(
                $this->session,
                password_hash('correct-password', PASSWORD_DEFAULT),
                $this->rateLimiter,
            ),
            $this->csrf,
            new OAuthState($this->session),
            $this->session,
            new PageRenderer(\dirname(__DIR__, 3) . '/templates'),
            'https://app.example',
            'cron-secret',
        );
    }

    public function testPublicHomepageAndCoverDoNotRequireAuthentication(): void
    {
        $this->operations->publicHomepageData = ['playlists' => [[
            'id' => 2,
            'name' => 'Public Playlist',
            'description' => 'Public Description',
            'cover_url' => '/playlist-covers/2',
            'spotify_url' => 'https://open.spotify.com/playlist/public-playlist',
            'synced_at' => '24.09.2026, 12:00',
            'synced_at_datetime' => '2026-09-24T12:00:00+02:00',
            'track_count' => 1,
            'tracks' => [[
                'position' => 1,
                'title' => 'Public Song',
                'artist' => 'Public Artist',
                'spotify_url' => 'https://open.spotify.com/track/public-track',
            ]],
        ]]];
        $this->operations->publicPlaylistCovers[2] = "\x89PNG\r\n";

        $response = $this->application->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertSame('no-cache', $response->headers['Cache-Control']);
        self::assertArrayNotHasKey('X-Robots-Tag', $response->headers);
        self::assertStringContainsString('SRF 3 Playlists auf Spotify', $response->body);
        self::assertStringContainsString('Public Playlist', $response->body);
        self::assertStringContainsString('<details class="public-tracks">', $response->body);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $response->body);
        self::assertStringNotContainsString('<form', $response->body);
        self::assertStringNotContainsString('_csrf', $response->body);
        self::assertStringNotContainsString('/admin/actions/', $response->body);
        self::assertSame([], $this->session->values);

        $cover = $this->application->handle(new Request('GET', '/playlist-covers/2'));
        self::assertSame(200, $cover->status);
        self::assertSame('image/png', $cover->headers['Content-Type']);
        self::assertSame('noindex, nofollow', $cover->headers['X-Robots-Tag']);
        self::assertSame("\x89PNG\r\n", $cover->body);
        self::assertSame(404, $this->application->handle(new Request('GET', '/playlist-covers/3'))->status);
    }

    public function testPublicHomepageFailureIsGenericAndRetryable(): void
    {
        $this->operations->publicHomepageException = new RuntimeException('database <secret>');
        $response = $this->application->handle(new Request('GET', '/'));

        self::assertSame(503, $response->status);
        self::assertSame('60', $response->headers['Retry-After']);
        self::assertStringContainsString('Fehler-ID', $response->body);
        self::assertStringContainsString('name="robots" content="noindex"', $response->body);
        self::assertStringNotContainsString('database', $response->body);
        self::assertStringNotContainsString('RuntimeException', $response->body);
        self::assertSame([], $this->session->values);
    }

    public function testSpotifyTrackSearchRequiresAuthenticationAndReturnsJson(): void
    {
        $unauthorized = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search', [
            'artist' => 'Artist',
        ]));
        self::assertSame(401, $unauthorized->status);
        self::assertSame('application/problem+json; charset=utf-8', $unauthorized->headers['Content-Type']);
        self::assertSame('noindex, nofollow', $unauthorized->headers['X-Robots-Tag']);

        $this->login();
        $this->operations->spotifySearchResult = [
            'items' => [['id' => 'track000001', 'title' => 'Song']],
            'offset' => 10,
            'limit' => 10,
            'has_more' => true,
        ];

        $response = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search', [
            'title' => 'Song',
            'artist' => 'Artist',
            'offset' => '10',
        ]));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame($this->operations->spotifySearchResult, json_decode($response->body, true));
        self::assertSame([
            ['title' => 'Song', 'artist' => 'Artist', 'offset' => '10'],
        ], $this->operations->spotifySearches);
    }

    public function testSpotifyTrackSearchErrorsUseProblemJson(): void
    {
        $this->login();

        $this->operations->spotifySearchException = new \InvalidArgumentException('invalid search');
        $invalid = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search'));
        self::assertSame(422, $invalid->status);
        self::assertSame('application/problem+json; charset=utf-8', $invalid->headers['Content-Type']);
        self::assertSame('VALIDATION_FAILED', json_decode($invalid->body, true)['title']);

        $this->operations->spotifySearchException = new SpotifyNotAuthorized('authorization required');
        $notAuthorized = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search'));
        self::assertSame(409, $notAuthorized->status);
        self::assertSame('SPOTIFY_NOT_AUTHORIZED', json_decode($notAuthorized->body, true)['title']);

        $this->operations->spotifySearchException = new SpotifyRateLimited(17);
        $limited = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search'));
        self::assertSame(429, $limited->status);
        self::assertSame('17', $limited->headers['Retry-After']);
        self::assertSame('SPOTIFY_RATE_LIMITED', json_decode($limited->body, true)['title']);

        $this->operations->spotifySearchException = new RuntimeException('unexpected failure');
        $failed = $this->application->handle(new Request('GET', '/admin/spotify/tracks/search'));
        self::assertSame(500, $failed->status);
        self::assertSame('application/problem+json; charset=utf-8', $failed->headers['Content-Type']);
        self::assertSame('OPERATION_FAILED', json_decode($failed->body, true)['title']);
    }

    public function testLoginRequiresCsrfAndRotatesSession(): void
    {
        $page = $this->application->handle(new Request('GET', '/admin/login', [
            'return_to' => '/admin/playlists/2',
        ]));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Anmelden', $page->body);
        self::assertStringContainsString('value="/admin/playlists/2"', $page->body);

        $forbidden = $this->application->handle(new Request('POST', '/admin/login', form: [
            '_csrf' => 'wrong',
            'password' => 'correct-password',
        ]));
        self::assertSame(403, $forbidden->status);

        $response = $this->application->handle(new Request('POST', '/admin/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'correct-password',
            'return_to' => '/admin/playlists/2',
        ]));
        self::assertSame(303, $response->status);
        self::assertSame('/admin/playlists/2', $response->headers['Location']);
        self::assertSame(1, $this->session->regenerations);
    }

    public function testLoginIsRateLimitedPerClientAddress(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $response = $this->application->handle(new Request(
                'POST',
                '/admin/login',
                form: ['_csrf' => $this->csrf->token(), 'password' => 'wrong-password'],
                clientAddress: '192.0.2.10',
            ));
            self::assertSame(401, $response->status);
        }

        $limited = $this->application->handle(new Request(
            'POST',
            '/admin/login',
            form: ['_csrf' => $this->csrf->token(), 'password' => 'correct-password'],
            clientAddress: '192.0.2.10',
        ));
        self::assertSame(429, $limited->status);
        self::assertSame('900', $limited->headers['Retry-After']);

        $otherClient = $this->application->handle(new Request(
            'POST',
            '/admin/login',
            form: ['_csrf' => $this->csrf->token(), 'password' => 'correct-password'],
            clientAddress: '198.51.100.20',
        ));
        self::assertSame(303, $otherClient->status);
    }

    public function testProtectedActionRejectsInvalidCsrf(): void
    {
        $this->login();

        $response = $this->application->handle(new Request('POST', '/admin/actions/import', form: [
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

        $response = $this->application->handle(new Request('POST', '/admin/actions/sync', form: [
            '_csrf' => $this->csrf->token(),
        ]));

        self::assertSame(303, $response->status);
        self::assertSame('/admin/spotify/authorize', $response->headers['Location']);

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
        $authorization = $this->application->handle(new Request('GET', '/admin/spotify/authorize'));
        self::assertSame(302, $authorization->status);
        $issuedState = $this->operations->authorizations[0]['state'];
        self::assertSame('https://app.example/admin/spotify/callback', $this->operations->authorizations[0]['redirect_uri']);

        $invalid = $this->application->handle(new Request('GET', '/admin/spotify/callback', [
            'state' => 'wrong',
            'code' => 'code-value',
        ]));
        self::assertSame(403, $invalid->status);
        self::assertSame([], $this->operations->exchanges);

        $this->application->handle(new Request('GET', '/admin/spotify/authorize'));
        $freshState = $this->operations->authorizations[1]['state'];
        $callback = $this->application->handle(new Request('GET', '/admin/spotify/callback', [
            'state' => $freshState,
            'code' => 'code-value',
        ]));
        self::assertSame(303, $callback->status);
        self::assertSame('code-value', $this->operations->exchanges[0]['code']);

        $replay = $this->application->handle(new Request('GET', '/admin/spotify/callback', [
            'state' => $freshState,
            'code' => 'code-value',
        ]));
        self::assertSame(403, $replay->status);
        self::assertSame(1, \count($this->operations->exchanges));
    }

    public function testAuthenticatedDashboardActionsMatchesAndLogout(): void
    {
        $this->login();

        $loginPage = $this->application->handle(new Request('GET', '/admin/login'));
        self::assertSame(303, $loginPage->status);

        $dashboard = $this->application->handle(new Request('GET', '/admin'));
        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('Playlists', $dashboard->body);

        $token = $this->csrf->token();
        $import = $this->application->handle(new Request('POST', '/admin/actions/import', form: [
            '_csrf' => $token,
            'from_date' => '2026-08-24',
            'to_date' => '2026-08-24',
        ]));
        self::assertSame(303, $import->status);
        self::assertSame('manual', $this->operations->imports[0]['trigger']);
        self::assertStringContainsString(
            'Import abgeschlossen',
            $this->application->handle(new Request('GET', '/admin'))->body,
        );

        self::assertSame(303, $this->application->handle(new Request('POST', '/admin/actions/sync', form: [
            '_csrf' => $token,
        ]))->status);
        self::assertSame(['manual'], $this->operations->synchronizations);
        self::assertStringContainsString(
            'Spotify synchronisiert: 2 Playlists, 4 Tracks.',
            $this->application->handle(new Request('GET', '/admin'))->body,
        );

        $selected = $this->application->handle(new Request('POST', '/admin/matches/42', form: [
            '_csrf' => $token,
            'track' => 'spotify:track:test',
            'return_to' => '/admin/playlists/7',
        ]));
        self::assertSame(303, $selected->status);
        self::assertSame('/admin/playlists/7', $selected->headers['Location']);
        self::assertSame([['song_id' => 42, 'track' => 'spotify:track:test']], $this->operations->selectedMatches);

        $reset = $this->application->handle(new Request('POST', '/admin/matches/43', form: [
            '_csrf' => $token,
            'action' => 'reset',
            'return_to' => 'https://attacker.example/redirect',
        ]));
        self::assertSame(303, $reset->status);
        self::assertSame('/admin', $reset->headers['Location']);
        self::assertSame([43], $this->operations->resetMatches);

        $invalidLogout = $this->application->handle(new Request('POST', '/admin/logout', form: ['_csrf' => 'wrong']));
        self::assertSame(403, $invalidLogout->status);
        $logout = $this->application->handle(new Request('POST', '/admin/logout', form: ['_csrf' => $token]));
        self::assertSame(303, $logout->status);
        self::assertSame('/', $logout->headers['Location']);
        self::assertTrue($this->session->destroyed);
    }

    public function testDashboardDoesNotRenderRankingTable(): void
    {
        $this->operations->ranking = [[
            'song_id' => 42,
            'title' => 'Test <Song>',
            'artist' => 'Artist & Co.',
            'play_count' => 2,
            'match_status' => 'accepted',
            'play_times' => [
                ['datetime' => '2026-08-01T12:34:00+02:00', 'label' => '01.08.2026, 12:34'],
                ['datetime' => '2026-07-31T08:15:00+02:00', 'label' => '31.07.2026, 08:15'],
            ],
        ]];
        $this->login();

        $dashboard = $this->application->handle(new Request('GET', '/admin'));

        self::assertSame(200, $dashboard->status);
        self::assertStringNotContainsString('data-dialog-target="play-history-42"', $dashboard->body);
        self::assertStringNotContainsString('Meistgespielte Songs', $dashboard->body);
    }

    public function testDashboardRendersSpotifySearchDialogForOpenMatch(): void
    {
        $this->operations->unresolvedMatches = [[
            'song_id' => 42,
            'title' => 'Test Song',
            'artist' => 'Artist (CH)',
            'play_count' => 3,
            'status' => 'review',
            'spotify_track_id' => 'candidate0001',
            'spotify_title' => 'Candidate Song',
            'spotify_artist' => 'Candidate Artist',
            'spotify_duration_ms' => 181_000,
        ]];
        $this->login();

        $dashboard = $this->application->handle(new Request('GET', '/admin'));

        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('data-dialog-target="spotify-match-42"', $dashboard->body);
        self::assertStringContainsString('id="spotify-match-42"', $dashboard->body);
        self::assertStringContainsString('value="Artist" maxlength="200"', $dashboard->body);
        self::assertStringContainsString('Candidate Song', $dashboard->body);
        self::assertStringContainsString('Bisheriger Vorschlag', $dashboard->body);
        self::assertStringContainsString('gilt für diesen SRF-Song in allen Playlists', $dashboard->body);
        self::assertStringContainsString('name="scope" value="global"', $dashboard->body);
        self::assertStringContainsString('name="return_to" value="/admin"', $dashboard->body);
        self::assertStringNotContainsString('value="reject"', $dashboard->body);
    }

    public function testDashboardLinksConfiguredPlaylistsByCoverAndName(): void
    {
        $this->operations->playlists = [[
            'id' => 2,
            'name' => 'SRF 3 - Der Morgen',
            'description' => 'Werktags von 06:00 bis 10:00 Uhr.',
            'cover_url' => '/admin/playlists/2/cover',
        ], [
            'id' => 4,
            'name' => 'Playlist ohne Cover',
            'description' => 'Fallback testen.',
            'cover_url' => null,
        ]];
        $this->login();

        $dashboard = $this->application->handle(new Request('GET', '/admin'));

        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('href="/admin/playlists/2"', $dashboard->body);
        self::assertStringContainsString('src="/admin/playlists/2/cover"', $dashboard->body);
        self::assertStringContainsString('SRF 3 - Der Morgen', $dashboard->body);
        self::assertStringContainsString('Werktags von 06:00 bis 10:00 Uhr.', $dashboard->body);
        self::assertStringContainsString('href="/admin/playlists/4"', $dashboard->body);
        self::assertStringContainsString('playlist-cover-placeholder', $dashboard->body);
    }

    public function testPlaylistDetailRequiresAuthentication(): void
    {
        $detail = $this->application->handle(new Request('GET', '/admin/playlists/2'));
        $cover = $this->application->handle(new Request('GET', '/admin/playlists/2/cover'));

        self::assertSame(302, $detail->status);
        self::assertSame('/admin/login?return_to=%2Fadmin%2Fplaylists%2F2', $detail->headers['Location']);
        self::assertSame(302, $cover->status);
        self::assertSame('/admin/login', $cover->headers['Location']);
    }

    public function testPlaylistDetailRendersCurrentRankingAndPlayHistory(): void
    {
        $this->operations->playlistDetails[2] = [
            'playlist' => [
                'id' => 2,
                'name' => 'SRF 3 - Der Morgen',
                'description' => 'Werktags von 06:00 bis 10:00 Uhr.',
                'cover_url' => '/admin/playlists/2/cover',
            ],
            'ranking' => [[
                'song_id' => 42,
                'title' => 'Test <Song>',
                'artist' => 'Artist & Co.',
                'play_count' => 2,
                'match_status' => 'accepted',
                'spotify_track_id' => 'current00001',
                'spotify_title' => 'Current Spotify Song',
                'spotify_artist' => 'Current Spotify Artist',
                'spotify_duration_ms' => 180_000,
                'play_times' => [
                    ['datetime' => '2026-08-01T12:34:00+02:00', 'label' => '01.08.2026, 12:34'],
                    ['datetime' => '2026-07-31T08:15:00+02:00', 'label' => '31.07.2026, 08:15'],
                ],
            ], [
                'song_id' => 43,
                'title' => 'Pending Song',
                'artist' => 'Pending Artist',
                'play_count' => 1,
                'match_status' => 'pending',
                'play_times' => [],
            ], [
                'song_id' => 44,
                'title' => 'Rejected Song',
                'artist' => 'Rejected Artist',
                'play_count' => 1,
                'match_status' => 'rejected',
                'play_times' => [],
            ]],
            'skipped' => [[
                'song_id' => 45,
                'title' => 'Missing Match',
                'artist' => 'Unmatched Artist',
                'play_count' => 1,
                'match_status' => 'pending',
                'airplay_rank' => 4,
                'skip_reason' => 'missing_match',
                'play_times' => [],
            ]],
            'target' => [
                'requested_count' => 50,
                'track_count' => 3,
                'ignored_count' => 0,
                'missing_match_count' => 1,
                'duplicate_track_count' => 0,
            ],
        ];
        $this->login();

        $detail = $this->application->handle(new Request('GET', '/admin/playlists/2'));

        self::assertSame(200, $detail->status);
        self::assertStringContainsString('SRF 3 - Der Morgen', $detail->body);
        self::assertStringContainsString('src="/admin/playlists/2/cover"', $detail->body);
        self::assertStringContainsString('href="/admin"', $detail->body);
        self::assertStringNotContainsString('Aktuelles Ranking', $detail->body);
        self::assertStringNotContainsString('Meistgespielte Songs', $detail->body);
        self::assertStringContainsString('data-dialog-target="play-history-42"', $detail->body);
        self::assertStringContainsString('Test &lt;Song&gt;', $detail->body);
        self::assertStringContainsString('status-pending', $detail->body);
        self::assertStringContainsString('status-rejected', $detail->body);
        self::assertStringContainsString('Nächster Spotify-Sync', $detail->body);
        self::assertStringContainsString('Nicht im Sync-Ziel', $detail->body);
        self::assertStringContainsString('Kein akzeptierter Spotify-Match', $detail->body);
        self::assertStringContainsString('data-dialog-target="ignore-song-42"', $detail->body);
        self::assertStringContainsString('data-dialog-target="spotify-match-42"', $detail->body);
        self::assertStringContainsString('data-dialog-target="spotify-match-45"', $detail->body);
        self::assertStringContainsString('id="spotify-match-42"', $detail->body);
        self::assertStringContainsString('Aktuelle Zuordnung', $detail->body);
        self::assertStringContainsString('Current Spotify Song', $detail->body);
        self::assertStringContainsString('name="return_to" value="/admin/playlists/2"', $detail->body);
        self::assertStringContainsString('name="scope" value="playlist" checked', $detail->body);
        self::assertStringContainsString('name="reason" maxlength="500"', $detail->body);
        self::assertStringContainsString(
            '<time datetime="2026-08-01T12:34:00+02:00">01.08.2026, 12:34 Uhr</time>',
            $detail->body,
        );
    }

    public function testPlaylistDetailUsesNeutralCoverFallback(): void
    {
        $this->operations->playlistDetails[4] = [
            'playlist' => [
                'id' => 4,
                'name' => 'Playlist ohne Cover',
                'description' => 'Fallback testen.',
                'cover_url' => null,
            ],
            'ranking' => [],
        ];
        $this->login();

        $detail = $this->application->handle(new Request('GET', '/admin/playlists/4'));

        self::assertSame(200, $detail->status);
        self::assertStringContainsString('playlist-cover-placeholder', $detail->body);
        self::assertStringNotContainsString('src="/admin/playlists/4/cover"', $detail->body);
    }

    public function testPlaylistCoverAndUnknownPlaylistResponses(): void
    {
        $this->operations->playlistCovers[2] = "\x89PNG\r\n";
        $this->login();

        $cover = $this->application->handle(new Request('GET', '/admin/playlists/2/cover'));
        self::assertSame(200, $cover->status);
        self::assertSame('image/png', $cover->headers['Content-Type']);
        self::assertSame("\x89PNG\r\n", $cover->body);

        $missing = $this->application->handle(new Request('GET', '/admin/playlists/999'));
        self::assertSame(404, $missing->status);
        self::assertStringContainsString('<!doctype html>', $missing->body);
        self::assertStringContainsString('Seite nicht gefunden', $missing->body);

        $invalid = $this->application->handle(new Request('GET', '/admin/playlists/not-an-id'));
        self::assertSame(404, $invalid->status);
        self::assertStringContainsString('<!doctype html>', $invalid->body);
    }

    public function testIgnoredSongsPageAndMutationsRequireValidScopeAndCsrf(): void
    {
        $this->operations->ignoredSongsPage = [
            'songs' => [[
                'song_id' => 42,
                'title' => 'Ignored Song',
                'artist' => 'Ignored Artist',
                'rules' => [[
                    'id' => 7,
                    'playlist_name' => 'SRF 3 - Top 50',
                    'is_global' => false,
                    'is_active' => true,
                    'reason' => 'Not suitable',
                    'ignored_at' => '22.09.2026, 10:00',
                    'reactivated_at' => null,
                ]],
                'active_specific_playlists' => ['SRF 3 - Top 50'],
                'has_active_global' => false,
                'active_rule_count' => 1,
                'can_ignore_globally' => true,
            ]],
            'song_count' => 1,
            'active_rule_count' => 1,
            'include_history' => false,
        ];
        $this->login();
        $token = $this->csrf->token();

        $page = $this->application->handle(new Request('GET', '/admin/ignored-songs', ['history' => '1']));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Ignored Song', $page->body);
        self::assertStringContainsString('Not suitable', $page->body);
        self::assertStringContainsString('name="history"', $page->body);

        $ignored = $this->application->handle(new Request('POST', '/admin/ignored-songs', form: [
            '_csrf' => $token,
            'song_id' => '42',
            'playlist_id' => '2',
            'return_to' => '/admin/playlists/2',
            'scope' => 'playlist',
            'reason' => 'Too repetitive',
        ]));
        self::assertSame(303, $ignored->status);
        self::assertSame('/admin/playlists/2', $ignored->headers['Location']);
        self::assertSame([
            ['song_id' => 42, 'playlist_id' => 2, 'reason' => 'Too repetitive'],
        ], $this->operations->ignoredSongs);

        $reactivated = $this->application->handle(new Request(
            'POST',
            '/admin/ignored-songs/7/reactivate',
            form: ['_csrf' => $token, 'return_history' => '1'],
        ));
        self::assertSame(303, $reactivated->status);
        self::assertSame('/admin/ignored-songs?history=1', $reactivated->headers['Location']);
        self::assertSame([7], $this->operations->reactivatedRules);

        $invalidScope = $this->application->handle(new Request('POST', '/admin/ignored-songs', form: [
            '_csrf' => $token,
            'song_id' => '42',
            'scope' => 'unknown',
        ]));
        self::assertSame(422, $invalidScope->status);

        $invalidCsrf = $this->application->handle(new Request('POST', '/admin/ignored-songs', form: [
            '_csrf' => 'wrong',
            'song_id' => '42',
            'scope' => 'global',
        ]));
        self::assertSame(403, $invalidCsrf->status);
    }

    public function testSyncWarningAppearsInFlashAndDashboardHistory(): void
    {
        $this->operations->synchronizeResult = [
            'playlist_count' => 1,
            'track_count' => 47,
            'unresolved_count' => 1,
            'has_warnings' => true,
            'total_track_count' => 47,
            'total_requested_count' => 50,
            'total_unresolved_count' => 1,
            'total_ignored_count' => 2,
            'total_duplicate_track_count' => 3,
        ];
        $this->operations->recentSyncs = [[
            'playlist_name' => 'SRF 3 - Top 50',
            'correlation_id' => 'test-sync',
            'trigger_type' => 'manual',
            'status' => 'succeeded',
            'requested_count' => 50,
            'track_count' => 47,
            'ignored_count' => 2,
            'unresolved_count' => 1,
            'duplicate_track_count' => 3,
            'spotify_snapshot_id' => 'snapshot',
            'error_summary' => null,
            'started_at' => '2026-09-22 10:00:00',
            'finished_at' => '2026-09-22 10:01:00',
        ]];
        $this->login();

        $sync = $this->application->handle(new Request('POST', '/admin/actions/sync', form: [
            '_csrf' => $this->csrf->token(),
        ]));
        self::assertSame(303, $sync->status);

        $dashboard = $this->application->handle(new Request('GET', '/admin'));

        self::assertStringContainsString('notice-warning', $dashboard->body);
        self::assertStringContainsString('47 von 50 Tracks', $dashboard->body);
        self::assertStringContainsString('2 ignoriert, 1 ohne Match, 3 Duplikate', $dashboard->body);
        self::assertStringContainsString('status-warning', $dashboard->body);
        self::assertStringContainsString('47 / 50', $dashboard->body);
    }

    public function testFailedLoginHealthAndUnknownRoute(): void
    {
        $failed = $this->application->handle(new Request('POST', '/admin/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'wrong-password',
        ]));
        self::assertSame(401, $failed->status);
        self::assertStringContainsString('Passwort nicht korrekt', $failed->body);

        self::assertSame(200, $this->application->handle(new Request('GET', '/health'))->status);
        $this->login();
        self::assertSame(404, $this->application->handle(new Request('GET', '/missing'))->status);
        foreach (['/login', '/playlists/2', '/ignored-songs', '/spotify/authorize'] as $legacyPath) {
            self::assertSame(404, $this->application->handle(new Request('GET', $legacyPath))->status);
        }
        self::assertSame(404, $this->application->handle(new Request('GET', '/administrator'))->status);
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
        $this->application->handle(new Request('GET', '/admin/spotify/authorize'));
        $state = $this->operations->authorizations[0]['state'];

        $response = $this->application->handle(new Request('GET', '/admin/spotify/callback', [
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
        self::assertSame(422, $this->application->handle(new Request('POST', '/admin/actions/import', form: [
            '_csrf' => $token,
        ]))->status);

        $this->operations->importException = new ImportLocked('locked');
        self::assertSame(409, $this->application->handle(new Request('POST', '/admin/actions/import', form: [
            '_csrf' => $token,
        ]))->status);

        $this->operations->synchronizeException = new SpotifyRateLimited(17);
        $limited = $this->application->handle(new Request('POST', '/admin/actions/sync', form: ['_csrf' => $token]));
        self::assertSame(503, $limited->status);
        self::assertSame('17', $limited->headers['Retry-After']);

        $this->operations->synchronizeException = new RuntimeException('Spotify <diagnostic> detail');
        $errorLog = sys_get_temp_dir() . '/srf3spotify-web-error-' . bin2hex(random_bytes(8)) . '.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        try {
            $unexpected = $this->application->handle(new Request('POST', '/admin/actions/sync', form: [
                '_csrf' => $token,
            ]));
            self::assertSame(500, $unexpected->status);
            self::assertStringContainsString('Technische Details', $unexpected->body);
            self::assertStringContainsString('RuntimeException', $unexpected->body);
            self::assertStringContainsString('Spotify &lt;diagnostic&gt; detail', $unexpected->body);
            self::assertStringNotContainsString('Spotify <diagnostic> detail', $unexpected->body);
            self::assertStringContainsString('POST /admin/actions/sync', $unexpected->body);
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
            self::assertStringContainsString('[' . $errorId . '] POST /admin/actions/sync', $logContents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }
            @unlink($errorLog);
        }
    }

    private function login(): void
    {
        $response = $this->application->handle(new Request('POST', '/admin/login', form: [
            '_csrf' => $this->csrf->token(),
            'password' => 'correct-password',
        ]));
        self::assertSame(303, $response->status);
    }
}
