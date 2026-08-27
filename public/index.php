<?php

declare(strict_types=1);

use App\ApplicationFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Support\Config;
use App\Support\Environment;
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
if ($request->method === 'GET' && $request->path === '/health') {
    Response::json(['status' => 'ok'])->send();
}

$root = \dirname(__DIR__);
try {
    Environment::load($root);
    $config = Config::fromEnvironment();
    $factory = new ApplicationFactory($config, $root);
    $session = new NativeSessionStore(str_starts_with($config->required('APP_URL'), 'https://'));
    $operations = new DefaultWebOperations(
        $factory,
        new DashboardRepository($factory->connection()),
    );
    $application = new WebApplication(
        $operations,
        new OwnerAuthentication($session, $config->string('ADMIN_PASSWORD_HASH')),
        new CsrfGuard($session),
        new OAuthState($session),
        $session,
        new PageRenderer($root . '/templates'),
        $config->required('APP_URL'),
        $config->string('CRON_TOKEN'),
    );

    $application->handle($request)->send();
} catch (Throwable $exception) {
    error_log(\sprintf('%s: %s', $exception::class, $exception->getMessage()));
    Response::html(
        '<!doctype html><html lang="de"><meta charset="utf-8"><title>Nicht verfügbar</title>'
        . '<body><main><h1>Anwendung nicht verfügbar</h1><p>Konfiguration oder Datenbank prüfen.</p></main></body></html>',
        503,
    )->send();
}
