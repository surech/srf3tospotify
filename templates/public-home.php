<?php $playlists = is_array($playlists ?? null) ? $playlists : []; ?>
<!doctype html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Automatisch zusammengestellte SRF 3 Playlists auf Spotify.">
  <title>SRF 3 Playlists auf Spotify</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="public-page">
  <header class="public-header">
    <div class="page-width public-header-inner">
      <a href="/" class="public-brand" aria-label="SRF 3 Playlists auf Spotify">SRF<span>3</span> Playlists</a>
      <a class="button button-secondary" href="/admin">Administration</a>
    </div>
  </header>
  <main>
    <section class="public-intro">
      <div class="page-width public-intro-inner">
        <p class="eyebrow">Musik von SRF 3</p>
        <h1>SRF 3 Playlists auf Spotify</h1>
        <p>Automatisch aus dem Musikprogramm von SRF 3 zusammengestellte Playlists. Öffne sie direkt auf Spotify oder entdecke hier die zuletzt synchronisierten Songs.</p>
      </div>
    </section>
    <section class="page-width public-playlists" aria-labelledby="playlist-heading">
      <div class="section-heading">
        <div><p class="eyebrow">Aktuelle Auswahl</p><h2 id="playlist-heading">Playlists</h2></div>
      </div>
      <?php if ($playlists === []): ?>
        <p class="public-empty">Aktuell sind noch keine Playlists verfügbar.</p>
      <?php else: ?>
        <div class="public-playlist-list">
          <?php foreach ($playlists as $playlist): ?>
            <article class="public-playlist">
              <div class="public-playlist-summary">
                <?php if (is_string($playlist['cover_url'] ?? null)): ?>
                  <img class="public-playlist-cover" src="<?= $escape($playlist['cover_url']) ?>" alt="">
                <?php else: ?>
                  <span class="public-playlist-cover public-cover-placeholder" aria-hidden="true">SRF<span>3</span></span>
                <?php endif; ?>
                <div class="public-playlist-copy">
                  <h3><?= $escape($playlist['name'] ?? '') ?></h3>
                  <p><?= $escape($playlist['description'] ?? '') ?></p>
                  <p class="public-updated">Zuletzt aktualisiert: <time datetime="<?= $escape($playlist['synced_at_datetime'] ?? '') ?>"><?= $escape($playlist['synced_at'] ?? '') ?> Uhr</time></p>
                </div>
                <a class="button button-primary public-spotify-link" href="<?= $escape($playlist['spotify_url'] ?? '') ?>" target="_blank" rel="noopener noreferrer">Auf Spotify öffnen</a>
              </div>
              <details class="public-tracks">
                <summary><?= $escape($playlist['track_count'] ?? 0) ?> Songs anzeigen</summary>
                <ol>
                  <?php foreach (($playlist['tracks'] ?? []) as $track): ?>
                    <li value="<?= $escape($track['position'] ?? '') ?>">
                      <span><strong><?= $escape($track['title'] ?? '') ?></strong><small><?= $escape($track['artist'] ?? '') ?></small></span>
                      <a href="<?= $escape($track['spotify_url'] ?? '') ?>" target="_blank" rel="noopener noreferrer">Spotify</a>
                    </li>
                  <?php endforeach; ?>
                </ol>
              </details>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <footer class="public-footer">
    <div class="page-width">Inoffizielles Projekt. Keine Verbindung zu oder Beauftragung durch SRF oder Spotify. Alle Marken gehören ihren jeweiligen Inhabern.</div>
  </footer>
</body>
</html>