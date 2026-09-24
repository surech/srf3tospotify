<?php

declare(strict_types=1);

use App\ApplicationFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Infrastructure\Database\DatabaseLoginRateLimiter;
use App\Support\Config;
use App\Support\Environment;
use App\Support\Uuid;
use App\Web\CsrfGuard;
use App\Web\DefaultWebOperations;
use App\Web\NativeSessionStore;
use App\Web\OAuthState;
use App\Web\OwnerAuthentication;
use App\Web\PageRenderer;
use App\Web\Request;
use App\Web\Response;
use App\Web\WebApplication;

require \dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::fromGlobals();
$root = \dirname(__DIR__);
try {
    $adminPasswordHashPreloaded = getenv('ADMIN_PASSWORD_HASH') !== false;
    Environment::load($root);
    $config = Config::fromEnvironment();
    if ($request->method === 'GET' && $request->path === '/health') {
        $adminPasswordHash = $config->string('ADMIN_PASSWORD_HASH');
        Response::json([
            'status' => 'ok',
            'admin_password_hash' => [
                'source' => $adminPasswordHashPreloaded ? 'process_environment' : '.env',
                'length' => \strlen($adminPasswordHash),
                'algorithm' => password_get_info($adminPasswordHash)['algoName'],
                'prefix' => substr($adminPasswordHash, 0, 7),
                'suffix' => substr($adminPasswordHash, -6),
                'sha256' => hash('sha256', $adminPasswordHash),
            ],
        ])->send();
    }
    $factory = new ApplicationFactory($config, $root);
    $session = new NativeSessionStore(str_starts_with($config->required('APP_URL'), 'https://'));
    $operations = new DefaultWebOperations(
        $factory,
        new DashboardRepository($factory->connection()),
    );
    $application = new WebApplication(
        $operations,
        new OwnerAuthentication(
            $session,
            $config->string('ADMIN_PASSWORD_HASH'),
            new DatabaseLoginRateLimiter($factory->connection(), $config->required('APP_KEY')),
        ),
        new CsrfGuard($session),
        new OAuthState($session),
        $session,
        new PageRenderer($root . '/templates'),
        $config->required('APP_URL'),
        $config->string('CRON_TOKEN'),
    );

    $application->handle($request)->send();
} catch (Throwable $exception) {
    $errorId = Uuid::v4();
    error_log(\sprintf('[%s] %s: %s', $errorId, $exception::class, $exception->getMessage()));
    if ($request->path === '/' || str_starts_with($request->path, '/playlist-covers/')) {
        Response::html(
            (new PageRenderer($root . '/templates'))->render('public-error', ['error_id' => $errorId]),
            503,
            ['Cache-Control' => 'no-store', 'Retry-After' => '60'],
        )->send();
    }
    Response::html(
        '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<title>Nicht verfügbar</title><body><main><h1>Anwendung nicht verfügbar</h1>'
        . '<p>Fehler-ID: ' . htmlspecialchars($errorId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></main></body></html>',
        503,
        ['X-Robots-Tag' => 'noindex, nofollow'],
    )->send();
}
