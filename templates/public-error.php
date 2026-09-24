<!doctype html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Vorübergehend nicht verfügbar</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="public-page">
  <main class="public-error page-width">
    <p class="eyebrow">Vorübergehend nicht verfügbar</p>
    <h1>Die Playlists konnten nicht geladen werden.</h1>
    <p>Bitte versuche es später erneut.</p>
    <p class="public-error-id">Fehler-ID: <code><?= $escape($error_id ?? '') ?></code></p>
    <a class="button button-secondary" href="/">Erneut versuchen</a>
  </main>
</body>
</html>