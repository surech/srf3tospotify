<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Anmelden · SRF3ToSpotify</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-panel" aria-labelledby="login-title">
      <p class="brand-mark">SRF<span>3</span> · Spotify</p>
      <h1 id="login-title">Anmelden</h1>
      <?php if (isset($error) && is_string($error)): ?>
        <p class="notice notice-error" role="alert"><?= $escape($error) ?></p>
      <?php endif; ?>
      <form method="post" action="/login" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit" class="button button-primary">Anmelden</button>
      </form>
    </section>
  </main>
</body>
</html>