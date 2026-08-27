<?php

declare(strict_types=1);

namespace App\Web;

use App\Application\Import\ImportLocked;
use App\Infrastructure\Spotify\SpotifyRateLimited;
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
        } catch (SpotifyRateLimited $exception) {
            return $this->error(
                $request,
                503,
                'SPOTIFY_RATE_LIMITED',
                $exception->getMessage(),
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (Throwable $exception) {
            error_log(\sprintf('%s: %s', $exception::class, $exception->getMessage()));

            return $this->error($request, 500, 'OPERATION_FAILED', 'Aktion konnte nicht abgeschlossen werden.');
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
            $data['flash'] = $this->consumeFlash();
            $data['yesterday'] = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))
                ->modify('-1 day')
                ->format('Y-m-d');

            return Response::html($this->renderer->render('dashboard', $data));
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
            $this->flash(\sprintf('Spotify synchronisiert: %d Tracks.', (int) ($result['track_count'] ?? 0)));

            return Response::redirect('/');
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

    /** @param array<string, string> $headers */
    private function error(Request $request, int $status, string $code, string $message, array $headers = []): Response
    {
        if (str_starts_with($request->path, '/internal/')) {
            return Response::json(['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]], $status, $headers);
        }

        return new Response(
            $status,
            $this->renderer->render('error', ['status' => $status, 'message' => $message]),
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

    private function flash(string $message): void
    {
        $this->session->set('flash', $message);
    }

    private function consumeFlash(): ?string
    {
        $message = $this->session->get('flash');
        $this->session->remove('flash');

        return \is_string($message) ? $message : null;
    }
}
