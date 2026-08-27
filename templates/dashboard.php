<?php
$statistics = is_array($statistics ?? null) ? $statistics : [];
$ranking = is_array($ranking ?? null) ? $ranking : [];
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
</head>
<body>
  <header class="topbar">
    <div class="page-width topbar-inner">
      <a href="/" class="brand-mark">SRF<span>3</span> · Spotify</a>
      <nav class="top-actions" aria-label="Kontoverwaltung">
        <a class="button button-secondary" href="/spotify/authorize">Spotify verbinden</a>
        <form method="post" action="/logout">
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
        <p class="notice notice-success" role="status"><?= $escape($flash) ?></p>
      <?php endif; ?>

      <section class="command-band" aria-labelledby="actions-title">
        <div class="section-heading">
          <div><p class="eyebrow">Steuerung</p><h2 id="actions-title">Aktualisieren</h2></div>
        </div>
        <div class="command-grid">
          <form method="post" action="/actions/import" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
            <label for="from-date">Von</label>
            <input id="from-date" name="from_date" type="date" value="<?= $escape($yesterday ?? '') ?>" required>
            <label for="to-date">Bis</label>
            <input id="to-date" name="to_date" type="date" value="<?= $escape($yesterday ?? '') ?>" required>
            <button type="submit" class="button button-primary">Importieren</button>
          </form>
          <form method="post" action="/actions/sync" class="sync-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
            <button type="submit" class="button button-spotify">Spotify synchronisieren</button>
          </form>
        </div>
      </section>

      <section aria-labelledby="ranking-title">
        <div class="section-heading">
          <div><p class="eyebrow">Letzte 30 Tage</p><h2 id="ranking-title">Meistgespielte Songs</h2></div>
          <span class="count-label"><?= $escape(count($ranking)) ?> Einträge</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Song</th><th>Künstler</th><th>Spiele</th><th>Spotify</th></tr></thead>
            <tbody>
            <?php foreach ($ranking as $index => $entry): ?>
              <tr>
                <td class="rank"><?= $escape($index + 1) ?></td>
                <td class="song-title"><?= $escape($entry['title'] ?? '') ?></td>
                <td><?= $escape($entry['artist'] ?? '') ?></td>
                <td><?= $escape($entry['play_count'] ?? 0) ?></td>
                <td><span class="status status-<?= $escape($entry['match_status'] ?? 'pending') ?>"><?= $escape($entry['match_status'] ?? 'pending') ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($ranking === []): ?>
              <tr><td colspan="5" class="empty-state">Noch keine Ausstrahlungen im Zeitraum.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
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
            <form method="post" action="/matches/<?= $escape($match['song_id'] ?? '') ?>" class="match-form">
              <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
              <label class="visually-hidden" for="track-<?= $escape($match['song_id'] ?? '') ?>">Spotify-Track</label>
              <input id="track-<?= $escape($match['song_id'] ?? '') ?>" name="track" placeholder="Spotify-URL oder Track-ID" required>
              <button type="submit" class="button button-secondary">Zuordnen</button>
              <button type="submit" name="action" value="reject" class="button button-quiet" formnovalidate>Ablehnen</button>
            </form>
          </article>
        <?php endforeach; ?>
        <?php if ($unresolvedMatches === []): ?><p class="empty-state">Keine offenen Zuordnungen.</p><?php endif; ?>
        </div>
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
              <thead><tr><th>Zeit</th><th>Status</th><th>Offen</th><th>Snapshot</th></tr></thead>
              <tbody>
              <?php foreach ($recentSyncs as $run): ?>
                <tr><td><?= $escape($run['started_at'] ?? '') ?></td><td><?= $escape($run['status'] ?? '') ?></td><td><?= $escape($run['unresolved_count'] ?? 0) ?></td><td class="truncate"><?= $escape($run['spotify_snapshot_id'] ?? '—') ?></td></tr>
              <?php endforeach; ?>
              <?php if ($recentSyncs === []): ?><tr><td colspan="4" class="empty-state">Keine Läufe.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </main>
</body>
</html>