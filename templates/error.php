<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fehler · SRF3ToSpotify</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-panel">
      <p class="brand-mark">SRF<span>3</span> · Spotify</p>
      <p class="error-code"><?= $escape($status ?? 500) ?> · <?= $escape($code ?? 'UNKNOWN_ERROR') ?></p>
      <h1>Aktion fehlgeschlagen</h1>
      <p class="notice notice-error"><?= $escape($message ?? 'Unbekannter Fehler.') ?></p>
      <?php if (isset($technicalDetails) && \is_array($technicalDetails)): ?>
        <section class="technical-details" aria-labelledby="technical-details-heading">
          <h2 id="technical-details-heading">Technische Details</h2>
          <dl>
            <div>
              <dt>Fehler-ID</dt>
              <dd><code><?= $escape($technicalDetails['error_id'] ?? '') ?></code></dd>
            </div>
            <div>
              <dt>Request</dt>
              <dd><code><?= $escape($technicalDetails['request'] ?? '') ?></code></dd>
            </div>
            <div>
              <dt>Exception</dt>
              <dd><code><?= $escape($technicalDetails['exception'] ?? '') ?></code></dd>
            </div>
            <div>
              <dt>Meldung</dt>
              <dd><code><?= $escape($technicalDetails['message'] ?? '') ?></code></dd>
            </div>
          </dl>
        </section>
      <?php endif; ?>
      <a class="button button-secondary" href="/">Zur Übersicht</a>
    </section>
  </main>
</body>
</html>