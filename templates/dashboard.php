<?php
$statistics = is_array($statistics ?? null) ? $statistics : [];
$playlists = is_array($playlists ?? null) ? $playlists : [];
$unresolvedMatches = is_array($unresolved_matches ?? null) ? $unresolved_matches : [];
$recentImports = is_array($recent_imports ?? null) ? $recent_imports : [];
$recentSyncs = is_array($recent_syncs ?? null) ? $recent_syncs : [];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Übersicht · SRF3ToSpotify</title>
  <link rel="stylesheet" href="/assets/app.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
  <header class="topbar">
    <div class="page-width topbar-inner">
      <a href="/admin" class="brand-mark">SRF<span>3</span> · Spotify</a>
      <nav class="top-actions" aria-label="Kontoverwaltung">
        <a class="button button-secondary" href="/">Öffentliche Seite</a>
        <a class="button button-secondary" href="/admin/ignored-songs">Ignorierte Songs</a>
        <a class="button button-secondary" href="/admin/spotify/authorize">Spotify verbinden</a>
        <form method="post" action="/admin/logout">
          <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
          <button type="submit" class="button button-quiet">Abmelden</button>
        </form>
      </nav>
    </div>
  </header>

  <main>
    <section class="summary-band">
      <div class="page-width">
        <div class="section-heading summary-heading">
          <div>
            <p class="eyebrow">Radioarchiv</p>
            <h1>SRF 3 in Zahlen</h1>
          </div>
          <p class="last-run">Letzter Import: <?= $escape($statistics['last_import'] ?? 'noch keiner') ?></p>
        </div>
        <dl class="metrics">
          <div><dt>Ausstrahlungen</dt><dd><?= $escape($statistics['plays'] ?? 0) ?></dd></div>
          <div><dt>Songs</dt><dd><?= $escape($statistics['songs'] ?? 0) ?></dd></div>
          <div><dt>Offene Matches</dt><dd><?= $escape($statistics['unresolved'] ?? 0) ?></dd></div>
          <div><dt>Letzter Sync</dt><dd class="metric-date"><?= $escape($statistics['last_sync'] ?? 'noch keiner') ?></dd></div>
        </dl>
      </div>
    </section>

    <div class="page-width content-stack">
      <?php if (isset($flash) && is_string($flash) && $flash !== ''): ?>
        <p class="notice notice-<?= $escape(($flash_type ?? 'success') === 'warning' ? 'warning' : 'success') ?>" role="status"><?= $escape($flash) ?></p>
      <?php endif; ?>

      <section class="command-band" aria-labelledby="actions-title">
        <div class="section-heading">
          <div><p class="eyebrow">Steuerung</p><h2 id="actions-title">Aktualisieren</h2></div>
        </div>
        <div class="command-grid">
          <form method="post" action="/admin/actions/import" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
            <label for="from-date">Von</label>
            <input id="from-date" name="from_date" type="date" value="<?= $escape($yesterday ?? '') ?>" required>
            <label for="to-date">Bis</label>
            <input id="to-date" name="to_date" type="date" value="<?= $escape($yesterday ?? '') ?>" required>
            <button type="submit" class="button button-primary">Importieren</button>
          </form>
          <form method="post" action="/admin/actions/sync" class="sync-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
            <button type="submit" class="button button-spotify">Spotify synchronisieren</button>
          </form>
        </div>
      </section>

      <section aria-labelledby="playlists-title">
        <div class="section-heading">
          <div><p class="eyebrow">Spotify</p><h2 id="playlists-title">Playlists</h2></div>
          <span class="count-label"><?= $escape(count($playlists)) ?> Playlists</span>
        </div>
        <div class="playlist-list">
          <?php foreach ($playlists as $playlist): ?>
            <?php $playlistUrl = '/admin/playlists/' . (int) ($playlist['id'] ?? 0); ?>
            <article class="playlist-row">
              <a class="playlist-cover-link" href="<?= $escape($playlistUrl) ?>" aria-label="<?= $escape(($playlist['name'] ?? 'Playlist') . ' anzeigen') ?>">
                <?php if (is_string($playlist['cover_url'] ?? null)): ?>
                  <img class="playlist-cover" src="<?= $escape($playlist['cover_url']) ?>" alt="">
                <?php else: ?>
                  <span class="playlist-cover playlist-cover-placeholder" aria-hidden="true">SRF<span>3</span></span>
                <?php endif; ?>
              </a>
              <div class="playlist-copy">
                <h3><a href="<?= $escape($playlistUrl) ?>"><?= $escape($playlist['name'] ?? '') ?></a></h3>
                <p><?= $escape($playlist['description'] ?? '') ?></p>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if ($playlists === []): ?>
            <p class="empty-state">Keine Playlists konfiguriert.</p>
          <?php endif; ?>
        </div>
      </section>

      <section aria-labelledby="matches-title">
        <div class="section-heading">
          <div><p class="eyebrow">Prüfliste</p><h2 id="matches-title">Offene Spotify-Zuordnungen</h2></div>
          <span class="count-label"><?= $escape(count($unresolvedMatches)) ?> offen</span>
        </div>
        <div class="match-list">
        <?php foreach ($unresolvedMatches as $match): ?>
          <article class="match-row">
            <div class="match-song">
              <strong><?= $escape($match['title'] ?? '') ?></strong>
              <span><?= $escape($match['artist'] ?? '') ?> · <?= $escape($match['play_count'] ?? 0) ?> Spiele</span>
            </div>
            <button
              type="button"
              class="button button-secondary match-dialog-trigger"
              data-dialog-target="spotify-match-<?= $escape($match['song_id'] ?? '') ?>"
              aria-haspopup="dialog"
            >Zuordnen</button>
          </article>
        <?php endforeach; ?>
        <?php if ($unresolvedMatches === []): ?><p class="empty-state">Keine offenen Zuordnungen.</p><?php endif; ?>
        </div>
        <?php foreach ($unresolvedMatches as $entry): ?>
          <?php $returnTo = '/admin'; require __DIR__ . '/_spotify-match-dialog.php'; ?>
        <?php endforeach; ?>
      </section>

      <section class="runs-grid" aria-label="Letzte Abläufe">
        <div>
          <div class="section-heading"><div><p class="eyebrow">Historie</p><h2>Importe</h2></div></div>
          <div class="table-wrap compact-table">
            <table>
              <thead><tr><th>Zeit</th><th>Status</th><th>Neu</th><th>Duplikate</th></tr></thead>
              <tbody>
              <?php foreach ($recentImports as $run): ?>
                <tr><td><?= $escape($run['started_at'] ?? '') ?></td><td><?= $escape($run['status'] ?? '') ?></td><td><?= $escape($run['inserted_count'] ?? 0) ?></td><td><?= $escape($run['duplicate_count'] ?? 0) ?></td></tr>
              <?php endforeach; ?>
              <?php if ($recentImports === []): ?><tr><td colspan="4" class="empty-state">Keine Läufe.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div>
          <div class="section-heading"><div><p class="eyebrow">Historie</p><h2>Spotify-Syncs</h2></div></div>
          <div class="table-wrap compact-table">
            <table>
              <thead><tr><th>Playlist</th><th>Zeit</th><th>Status</th><th>Tracks</th><th>Ursachen</th></tr></thead>
              <tbody>
              <?php foreach ($recentSyncs as $run): ?>
                <?php
                $requestedCount = (int) ($run['requested_count'] ?? 0);
                $trackCount = (int) ($run['track_count'] ?? 0);
                $hasWarning = ($run['status'] ?? '') === 'succeeded' && $requestedCount > 0 && $trackCount < $requestedCount;
                ?>
                <tr>
                  <td><?= $escape($run['playlist_name'] ?? '') ?></td>
                  <td><?= $escape($run['started_at'] ?? '') ?></td>
                  <td><span class="status <?= $hasWarning ? 'status-warning' : 'status-' . $escape($run['status'] ?? '') ?>"><?= $escape($hasWarning ? 'Warnung' : ($run['status'] ?? '')) ?></span></td>
                  <td><?= $escape($trackCount) ?> / <?= $escape($requestedCount) ?></td>
                  <td class="sync-causes"><?= $escape($run['ignored_count'] ?? 0) ?> ignoriert · <?= $escape($run['unresolved_count'] ?? 0) ?> ohne Match · <?= $escape($run['duplicate_track_count'] ?? 0) ?> doppelt</td>
                </tr>
              <?php endforeach; ?>
              <?php if ($recentSyncs === []): ?><tr><td colspan="5" class="empty-state">Keine Läufe.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </main>
</body>
</html>