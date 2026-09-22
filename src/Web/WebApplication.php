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
            return $this->route($request);
        } catch (CsrfViolation $exception) {
            return $this->error($request, 403, 'CSRF_INVALID', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, 422, 'VALIDATION_FAILED', $exception->getMessage());
        } catch (ImportLocked $exception) {
            return $this->error($request, 409, 'OPERATION_LOCKED', $exception->getMessage());
        } catch (SpotifyNotAuthorized $exception) {
            if (str_starts_with($request->path, '/internal/')) {
                return $this->error($request, 409, 'SPOTIFY_NOT_AUTHORIZED', $exception->getMessage());
            }

            return Response::redirect('/spotify/authorize');
        } catch (SpotifyRateLimited $exception) {
            return $this->error(
                $request,
                503,
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

            return $this->error(
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

    private function route(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/health') {
            return Response::json(['status' => 'ok']);
        }
        if (str_starts_with($request->path, '/internal/')) {
            return $this->internal($request);
        }
        if ($request->method === 'GET' && $request->path === '/login') {
            return $this->authentication->authenticated()
                ? Response::redirect('/')
                : Response::html($this->renderer->render('login', ['csrf' => $this->csrf->token()]));
        }
        if ($request->method === 'POST' && $request->path === '/login') {
            if (!$this->csrf->valid($request->form['_csrf'] ?? null)) {
                return $this->problem(403, 'CSRF_INVALID', 'Ungültige Formularsitzung.');
            }
            if (!$this->authentication->login($request->form['password'] ?? '')) {
                return Response::html($this->renderer->render('login', [
                    'csrf' => $this->csrf->rotate(),
                    'error' => 'Passwort nicht korrekt.',
                ]), 401);
            }
            $this->csrf->rotate();

            return Response::redirect('/');
        }
        if (!$this->authentication->authenticated()) {
            return Response::redirect('/login', 302);
        }
        if ($request->method === 'POST' && $request->path === '/logout') {
            if (!$this->csrf->valid($request->form['_csrf'] ?? null)) {
                return $this->problem(403, 'CSRF_INVALID', 'Ungültige Formularsitzung.');
            }
            $this->authentication->logout();

            return Response::redirect('/login');
        }
        if ($request->method === 'GET' && $request->path === '/') {
            $data = $this->operations->dashboard();
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();
            $data['yesterday'] = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))
                ->modify('-1 day')
                ->format('Y-m-d');

            return Response::html($this->renderer->render('dashboard', $data));
        }
        if ($request->method === 'GET' && $request->path === '/ignored-songs') {
            $data = $this->operations->ignoredSongs(($request->query['history'] ?? '') === '1');
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();

            return Response::html($this->renderer->render('ignored-songs', $data));
        }
        if ($request->method === 'GET' && preg_match('~^/playlists/(\d+)/cover$~', $request->path, $matches) === 1) {
            $cover = $this->operations->playlistCover((int) $matches[1]);
            if ($cover === null) {
                return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
            }

            return new Response(200, $cover, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }
        if ($request->method === 'GET' && preg_match('~^/playlists/(\d+)$~', $request->path, $matches) === 1) {
            $data = $this->operations->playlist((int) $matches[1]);
            if ($data === null) {
                return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
            }
            $data['csrf'] = $this->csrf->token();
            $data += $this->consumeFlash();

            return Response::html($this->renderer->render('playlist', $data));
        }
        if ($request->method === 'GET' && str_starts_with($request->path, '/playlists/')) {
            return $this->error($request, 404, 'NOT_FOUND', 'Seite nicht gefunden.');
        }
        if ($request->method === 'POST' && $request->path === '/actions/import') {
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

            return Response::redirect('/');
        }
        if ($request->method === 'POST' && $request->path === '/actions/sync') {
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

            return Response::redirect('/');
        }
        if ($request->method === 'POST' && $request->path === '/ignored-songs') {
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
            $sourcePlaylistId = (int) ($request->form['source_playlist_id'] ?? 0);

            return Response::redirect($sourcePlaylistId > 0 ? '/playlists/' . $sourcePlaylistId : '/ignored-songs');
        }
        if ($request->method === 'POST'
            && preg_match('~^/ignored-songs/(\d+)/reactivate$~', $request->path, $matches) === 1
        ) {
            $this->requireCsrf($request);
            $this->operations->reactivateSong((int) $matches[1]);
            $this->flash('Song reaktiviert. Die nächste Playlist-Auswahl berücksichtigt ihn wieder regulär.');

            return Response::redirect(
                ($request->form['return_history'] ?? '') === '1' ? '/ignored-songs?history=1' : '/ignored-songs',
            );
        }
        if ($request->method === 'POST' && preg_match('~^/matches/(\d+)$~', $request->path, $matches) === 1) {
            $this->requireCsrf($request);
            $songId = (int) $matches[1];
            if (($request->form['action'] ?? '') === 'reject') {
                $this->operations->rejectMatch($songId);
                $this->flash('Song für Spotify abgelehnt.');
            } else {
                $this->operations->selectMatch($songId, $request->form['track'] ?? '');
                $this->flash('Spotify-Zuordnung gespeichert.');
            }

            return Response::redirect('/');
        }
        if ($request->method === 'GET' && $request->path === '/spotify/authorize') {
            $redirectUri = $this->callbackUri();

            return Response::redirect($this->operations->authorizationUrl($this->oauthState->issue(), $redirectUri), 302);
        }
        if ($request->method === 'GET' && $request->path === '/spotify/callback') {
            if (!$this->oauthState->consume($request->query['state'] ?? null)) {
                return $this->problem(403, 'OAUTH_STATE_INVALID', 'Spotify-Anmeldung konnte nicht validiert werden.');
            }
            if (isset($request->query['error'])) {
                return $this->problem(400, 'OAUTH_DENIED', 'Spotify-Zugriff wurde nicht freigegeben.');
            }
            $this->operations->exchangeAuthorizationCode($request->query['code'] ?? '', $this->callbackUri());
            $this->flash('Spotify-Konto verbunden.');

            return Response::redirect('/');
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

    private function problem(int $status, string $code, string $message): Response
    {
        return Response::json([
            'type' => 'about:blank',
            'title' => $code,
            'status' => $status,
            'detail' => $message,
        ], $status, ['Content-Type' => 'application/problem+json; charset=utf-8']);
    }

    private function callbackUri(): string
    {
        return rtrim($this->applicationUrl, '/') . '/spotify/callback';
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
