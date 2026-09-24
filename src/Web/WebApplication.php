<?php

declare(strict_types=1);

namespace App\Web;

use App\Application\Import\ImportLocked;
use App\Infrastructure\Spotify\SpotifyNotAuthorized;
use App\Infrastructure\Spotify\SpotifyRateLimited;
use App\Support\Uuid;
use InvalidArgumentException;
use Throwable;

final readonly class WebApplication
{
    public function __construct(
        private WebOperations $operations,
        private OwnerAuthentication $authentication,
        private CsrfGuard $csrf,
        private OAuthState $oauthState,
        private SessionStore $session,
        private PageRenderer $renderer,
        private string $applicationUrl,
        private string $cronToken,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $response = $this->route($request);
        } catch (CsrfViolation $exception) {
            $response = $this->error($request, 403, 'CSRF_INVALID', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            $response = $this->error($request, 422, 'VALIDATION_FAILED', $exception->getMessage());
        } catch (ImportLocked $exception) {
            $response = $this->error($request, 409, 'OPERATION_LOCKED', $exception->getMessage());
        } catch (SpotifyNotAuthorized $exception) {
            if (str_starts_with($request->path, '/internal/') || $this->isJsonRoute($request)) {
                $response = $this->error($request, 409, 'SPOTIFY_NOT_AUTHORIZED', $exception->getMessage());
            } else {
                $response = Response::redirect('/admin/spotify/authorize');
            }
        } catch (SpotifyRateLimited $exception) {
            $response = $this->error(
                $request,
                $this->isJsonRoute($request) ? 429 : 503,
                'SPOTIFY_RATE_LIMITED',
                $exception->getMessage(),
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (Throwable $exception) {
            $errorId = Uuid::v4();
            error_log(\sprintf(
                '[%s] %s %s - %s: %s',
                $errorId,
                $request->method,
                $request->path,
                $exception::class,
                $exception->getMessage(),
            ));

            if ($this->isPublicRoute($request)) {
                $response = Response::html($this->renderer->render('public-error', [
                    'error_id' => $errorId,
                ]), 503, [
                    'Cache-Control' => 'no-store',
                    'Retry-After' => '60',
                ]);
            } else {
                $response = $this->error(
                    $request,
                    500,
                    'OPERATION_FAILED',
                    'Aktion konnte nicht abgeschlossen werden.',
                    technicalDetails: [
                        'error_id' => $errorId,
                        'request' => $request->method . ' ' . $request->path,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ],
                );
            }
        }

        return $this->applyResponsePolicy($request, $response);
    }

    private function route(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/health') {
            return Response::json(['status' => 'ok']);
        }
        if (str_starts_with($request->path, '/internal/')) {
            return $this->internal($request);
        }
        if ($request->method === 'GET' && $request->path === '/') {
            return Response::html(
                $this->renderer->render('public-home', $this->operations->publicHomepage()),
                headers: ['Cache-Control' => 'no-cache'],
            );
        }
        if ($request->method === 'GET'
            && preg_match('~^/playlist-covers/([1-9]\d*)$~D', $request->path, $matches) === 1
        ) {
            $cover = $this->operations->publicPlaylistCover((int) $matches[1]);
            if ($cover === null) {
                return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
            }

            return new Response(200, $cover, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
        if ($request->method === 'GET' && $request->path === '/admin/login') {
            $returnTo = $this->safeLoginReturnTo($request->query['return_to'] ?? null);

            return $this->authentication->authenticated()
                ? Response::redirect('/admin')
                : Response::html($this->renderer->render('login', [
                    'csrf' => $this->csrf->token(),
                    'return_to' => $returnTo,
                ]));
        }
        if ($request->method === 'POST' && $request->path === '/admin/login') {
            if (!$this->csrf->valid($request->form['_csrf'] ?? null)) {
                return $this->problem(403, 'CSRF_INVALID', 'Ungültige Formularsitzung.');
            }
            try {
                $authenticated = $this->authentication->login(
                    $request->form['password'] ?? '',
                    $request->clientAddress,
                );
            } catch (LoginRateLimited $exception) {
                return Response::html($this->renderer->render('login', [
                    'csrf' => $this->csrf->token(),
                    'error' => 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.',
                    'return_to' => $this->safeLoginReturnTo($request->form['return_to'] ?? null),
                ]), 429, ['Retry-After' => (string) $exception->retryAfterSeconds]);
            }
            if (!$authenticated) {
                return Response::html($this->renderer->render('login', [
                    'csrf' => $this->csrf->rotate(),
                    'error' => 'Passwort nicht korrekt.',
                    'return_to' => $this->safeLoginReturnTo($request->form['return_to'] ?? null),
                ]), 401);
            }
            $this->csrf->rotate();

            return Response::redirect($this->safeLoginReturnTo($request->form['return_to'] ?? null));
        }
        if ($request->path !== '/admin' && !str_starts_with($request->path, '/admin/')) {
            return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
        }
        if (!$this->authentication->authenticated()) {
            return $this->isJsonRoute($request)
                ? $this->problem(401, 'UNAUTHORIZED', 'Authentication is required.')
                : Response::redirect($this->loginLocation($request), 302);
        }
        if ($request->method === 'GET' && $request->path === '/admin/spotify/tracks/search') {
            return Response::json($this->operations->searchSpotifyTracks(
                $request->query['title'] ?? '',
                $request->query['artist'] ?? '',
                $request->query['offset'] ?? '0',
            ));
        }
        if ($request->method === 'POST' && $request->path === '/admin/logout') {
            if (!$this->csrf->valid($request->form['_csrf'] ?? null)) {
                return $this->problem(403, 'CSRF_INVALID', 'Ungültige Formularsitzung.');
            }
            $this->authentication->logout();

            return Response::redirect('/');
        }
        if ($request->method === 'GET' && $request->path === '/admin') {
            $data = $this->operations->dashboard();
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();
            $data['yesterday'] = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))
                ->modify('-1 day')
                ->format('Y-m-d');

            return Response::html($this->renderer->render('dashboard', $data));
        }
        if ($request->method === 'GET' && $request->path === '/admin/ignored-songs') {
            $data = $this->operations->ignoredSongs(($request->query['history'] ?? '') === '1');
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();

            return Response::html($this->renderer->render('ignored-songs', $data));
        }
        if ($request->method === 'GET'
            && preg_match('~^/admin/playlists/(\d+)/cover$~', $request->path, $matches) === 1
        ) {
            $cover = $this->operations->playlistCover((int) $matches[1]);
            if ($cover === null) {
                return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
            }

            return new Response(200, $cover, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }
        if ($request->method === 'GET'
            && preg_match('~^/admin/playlists/(\d+)$~', $request->path, $matches) === 1
        ) {
            $data = $this->operations->playlist((int) $matches[1]);
            if ($data === null) {
                return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
            }
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();

            return Response::html($this->renderer->render('playlist', $data));
        }
        if ($request->method === 'GET' && str_starts_with($request->path, '/admin/playlists/')) {
            return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
        }
        if ($request->method === 'POST' && $request->path === '/admin/actions/import') {
            $this->requireCsrf($request);
            $result = $this->operations->import(
                $request->form['from_date'] ?? '',
                $request->form['to_date'] ?? '',
                'manual',
            );
            $this->flash(\sprintf(
                'Import abgeschlossen: %d neu, %d bereits vorhanden.',
                (int) ($result['counts']['inserted'] ?? 0),
                (int) ($result['counts']['duplicates'] ?? 0),
            ));

            return Response::redirect('/admin');
        }
        if ($request->method === 'POST' && $request->path === '/admin/actions/sync') {
            $this->requireCsrf($request);
            $result = $this->operations->synchronize('manual');
            if (($result['has_warnings'] ?? false) === true) {
                $this->flash(\sprintf(
                    'Spotify synchronisiert: %d von %d Tracks. Warnung: %d ignoriert, %d ohne Match, %d Duplikate.',
                    (int) ($result['total_track_count'] ?? 0),
                    (int) ($result['total_requested_count'] ?? 0),
                    (int) ($result['total_ignored_count'] ?? 0),
                    (int) ($result['total_unresolved_count'] ?? 0),
                    (int) ($result['total_duplicate_track_count'] ?? 0),
                ), 'warning');
            } else {
                $this->flash(\sprintf(
                    'Spotify synchronisiert: %d Playlists, %d Tracks.',
                    (int) ($result['playlist_count'] ?? 0),
                    (int) ($result['total_track_count'] ?? 0),
                ));
            }

            return Response::redirect('/admin');
        }
        if ($request->method === 'POST' && $request->path === '/admin/ignored-songs') {
            $this->requireCsrf($request);
            $scope = $request->form['scope'] ?? '';
            if (!\in_array($scope, ['playlist', 'global'], true)) {
                throw new InvalidArgumentException('Ignore scope must be playlist or global.');
            }
            $playlistId = $scope === 'playlist' ? (int) ($request->form['playlist_id'] ?? 0) : null;
            if ($scope === 'playlist' && $playlistId < 1) {
                throw new InvalidArgumentException('Playlist scope requires a playlist.');
            }
            $this->operations->ignoreSong(
                (int) ($request->form['song_id'] ?? 0),
                $playlistId,
                $request->form['reason'] ?? null,
            );
            $this->flash('Song ignoriert. Spotify wird beim nächsten Sync aktualisiert.');
            $returnTo = $request->form['return_to'] ?? null;
            if (\is_string($returnTo) && $returnTo !== '') {
                return Response::redirect($this->safeReturnTo($returnTo));
            }
            $sourcePlaylistId = (int) ($request->form['source_playlist_id'] ?? 0);

            return Response::redirect(
                $sourcePlaylistId > 0
                    ? '/admin/playlists/' . $sourcePlaylistId
                    : '/admin/ignored-songs',
            );
        }
        if ($request->method === 'POST'
            && preg_match('~^/admin/ignored-songs/(\d+)/reactivate$~', $request->path, $matches) === 1
        ) {
            $this->requireCsrf($request);
            $this->operations->reactivateSong((int) $matches[1]);
            $this->flash('Song reaktiviert. Die nächste Playlist-Auswahl berücksichtigt ihn wieder regulär.');

            return Response::redirect(
                ($request->form['return_history'] ?? '') === '1'
                    ? '/admin/ignored-songs?history=1'
                    : '/admin/ignored-songs',
            );
        }
        if ($request->method === 'POST'
            && preg_match('~^/admin/matches/(\d+)$~', $request->path, $matches) === 1
        ) {
            $this->requireCsrf($request);
            $songId = (int) $matches[1];
            $action = $request->form['action'] ?? 'select';
            if ($action === 'reset') {
                $this->operations->resetMatch($songId);
                $this->flash('Spotify-Zuordnung zur manuellen Prüfung zurückgesetzt.');
            } elseif ($action === 'select') {
                $this->operations->selectMatch($songId, $request->form['track'] ?? '');
                $this->flash('Spotify-Zuordnung gespeichert.');
            } else {
                throw new InvalidArgumentException('Match action must be select or reset.');
            }

            return Response::redirect($this->safeReturnTo($request->form['return_to'] ?? null));
        }
        if ($request->method === 'GET' && $request->path === '/admin/spotify/authorize') {
            $redirectUri = $this->callbackUri();

            return Response::redirect($this->operations->authorizationUrl($this->oauthState->issue(), $redirectUri), 302);
        }
        if ($request->method === 'GET' && $request->path === '/admin/spotify/callback') {
            if (!$this->oauthState->consume($request->query['state'] ?? null)) {
                return $this->problem(403, 'OAUTH_STATE_INVALID', 'Spotify-Anmeldung konnte nicht validiert werden.');
            }
            if (isset($request->query['error'])) {
                return $this->problem(400, 'OAUTH_DENIED', 'Spotify-Zugriff wurde nicht freigegeben.');
            }
            $this->operations->exchangeAuthorizationCode($request->query['code'] ?? '', $this->callbackUri());
            $this->flash('Spotify-Konto verbunden.');

            return Response::redirect('/admin');
        }

        return $this->problem(404, 'NOT_FOUND', 'Seite nicht gefunden.');
    }

    private function internal(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return $this->problem(405, 'METHOD_NOT_ALLOWED', 'Nur POST erlaubt.');
        }
        $authorization = $request->header('authorization');
        $expected = 'Bearer ' . $this->cronToken;
        if ($this->cronToken === '' || !\is_string($authorization) || !hash_equals($expected, $authorization)) {
            return $this->problem(401, 'UNAUTHORIZED', 'Cron-Authentisierung fehlgeschlagen.');
        }
        if ($request->path === '/internal/cron/import') {
            $yesterday = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))
                ->modify('-1 day')
                ->format('Y-m-d');

            return Response::json($this->operations->import($yesterday, $yesterday, 'http-cron'));
        }
        if ($request->path === '/internal/cron/sync') {
            return Response::json($this->operations->synchronize('http-cron'));
        }
        if ($request->path === '/internal/maintenance/migrate') {
            return Response::json($this->operations->migrate());
        }

        return $this->problem(404, 'NOT_FOUND', 'Interne Route nicht gefunden.');
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->csrf->valid($request->form['_csrf'] ?? null)) {
            throw new CsrfViolation('Ungültige Formularsitzung.');
        }
    }

    /** @param array<string, string> $headers
     *  @param array{error_id: string, request: string, exception: class-string<Throwable>, message: string}|null $technicalDetails
     */
    private function error(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $headers = [],
        ?array $technicalDetails = null,
    ): Response {
        if (str_starts_with($request->path, '/internal/')) {
            return Response::json(['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]], $status, $headers);
        }
        if ($this->isJsonRoute($request)) {
            return $this->problem($status, $code, $message, $headers);
        }

        return new Response(
            $status,
            $this->renderer->render('error', [
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'technicalDetails' => $technicalDetails,
            ]),
            array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers),
        );
    }

    /** @param array<string, string> $headers */
    private function problem(int $status, string $code, string $message, array $headers = []): Response
    {
        return Response::json([
            'type' => 'about:blank',
            'title' => $code,
            'status' => $status,
            'detail' => $message,
        ], $status, array_merge(['Content-Type' => 'application/problem+json; charset=utf-8'], $headers));
    }

    private function isJsonRoute(Request $request): bool
    {
        return $request->path === '/admin/spotify/tracks/search';
    }

    private function safeReturnTo(?string $returnTo): string
    {
        if ($returnTo === '/admin'
            || (\is_string($returnTo) && preg_match('~^/admin/playlists/[1-9]\d*$~D', $returnTo) === 1)
        ) {
            return $returnTo;
        }

        return '/admin';
    }

    private function safeLoginReturnTo(?string $returnTo): string
    {
        if (!\is_string($returnTo)) {
            return '/admin';
        }
        if ($returnTo === '/admin'
            || preg_match('~^/admin/playlists/[1-9]\d*$~D', $returnTo) === 1
            || preg_match('~^/admin/ignored-songs(?:\?history=1)?$~D', $returnTo) === 1
        ) {
            return $returnTo;
        }

        return '/admin';
    }

    private function loginLocation(Request $request): string
    {
        $returnTo = null;
        if ($request->method === 'GET'
            && preg_match('~^/admin/playlists/[1-9]\d*$~D', $request->path) === 1
        ) {
            $returnTo = $request->path;
        } elseif ($request->method === 'GET' && $request->path === '/admin/ignored-songs') {
            $returnTo = $request->path
                . (($request->query['history'] ?? '') === '1' ? '?history=1' : '');
        }

        return $returnTo === null
            ? '/admin/login'
            : '/admin/login?return_to=' . rawurlencode($returnTo);
    }

    private function isPublicRoute(Request $request): bool
    {
        return $request->path === '/'
            || str_starts_with($request->path, '/playlist-covers/');
    }

    private function applyResponsePolicy(Request $request, Response $response): Response
    {
        if ($request->path === '/') {
            return $response;
        }

        return new Response(
            $response->status,
            $response->body,
            array_merge($response->headers, ['X-Robots-Tag' => 'noindex, nofollow']),
        );
    }

    private function callbackUri(): string
    {
        return rtrim($this->applicationUrl, '/') . '/admin/spotify/callback';
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->session->set('flash', $message);
        $this->session->set('flash_type', $type);
    }

    /** @return array{flash: string|null, flash_type: string} */
    private function consumeFlash(): array
    {
        $message = $this->session->get('flash');
        $type = $this->session->get('flash_type');
        $this->session->remove('flash');
        $this->session->remove('flash_type');

        return [
            'flash' => \is_string($message) ? $message : null,
            'flash_type' => $type === 'warning' ? 'warning' : 'success',
        ];
    }
}
