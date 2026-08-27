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
      <p class="error-code"><?= $escape($status ?? 500) ?></p>
      <h1>Aktion fehlgeschlagen</h1>
      <p class="notice notice-error"><?= $escape($message ?? 'Unbekannter Fehler.') ?></p>
      <a class="button button-secondary" href="/">Zur Übersicht</a>
    </section>
  </main>
</body>
</html>