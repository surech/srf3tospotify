<?php
$playlist = is_array($playlist ?? null) ? $playlist : [];
$ranking = is_array($ranking ?? null) ? $ranking : [];
$skipped = is_array($skipped ?? null) ? $skipped : [];
$target = is_array($target ?? null) ? $target : [];
$visibleEntries = array_merge($ranking, $skipped);
$skipReasonLabels = [
  'missing_match' => 'Kein akzeptierter Spotify-Match',
  'duplicate_track' => 'Spotify-Track bereits enthalten',
];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $escape($playlist['name'] ?? 'Playlist') ?> · SRF3ToSpotify</title>
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
      <div class="page-width playlist-detail-heading">
        <?php if (is_string($playlist['cover_url'] ?? null)): ?>
          <img class="playlist-cover playlist-detail-cover" src="<?= $escape($playlist['cover_url']) ?>" alt="">
        <?php else: ?>
          <span class="playlist-cover playlist-cover-placeholder playlist-detail-cover" aria-hidden="true">SRF<span>3</span></span>
        <?php endif; ?>
        <div class="playlist-detail-copy">
          <a class="back-link" href="/admin">Zur Übersicht</a>
          <p class="eyebrow">Playlist</p>
          <h1><?= $escape($playlist['name'] ?? '') ?></h1>
          <p class="playlist-description"><?= $escape($playlist['description'] ?? '') ?></p>
          <p class="last-run">Letzter Spotify-Sync: <?= $escape($playlist['last_sync_at'] ?? 'noch keiner') ?></p>
        </div>
      </div>
    </section>

    <div class="page-width content-stack">
      <?php if (isset($flash) && is_string($flash) && $flash !== ''): ?>
        <p class="notice notice-<?= $escape(($flash_type ?? 'success') === 'warning' ? 'warning' : 'success') ?>" role="status"><?= $escape($flash) ?></p>
      <?php endif; ?>

      <section aria-label="Songs der Playlist">
        <div class="section-heading">
          <div><p class="eyebrow">Vorschau</p><h2>Nächster Spotify-Sync</h2></div>
          <span class="count-label">
            <?= $escape(count($ranking)) ?><?= ($playlist['target_tracks'] ?? null) === null ? '' : ' / ' . $escape($playlist['target_tracks']) ?> Tracks
          </span>
        </div>
        <p class="target-note">Diese Reihenfolge wird beim nächsten manuellen oder geplanten Sync übernommen.</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Song</th><th>Künstler</th><th>Spiele</th><th>Spotify</th><th><span class="visually-hidden">Aktionen</span></th></tr></thead>
            <tbody>
            <?php foreach ($ranking as $index => $entry): ?>
              <?php $dialogId = 'play-history-' . (int) ($entry['song_id'] ?? $index); ?>
              <tr>
                <td class="rank"><?= $escape($index + 1) ?></td>
                <td class="song-title"><?= $escape($entry['title'] ?? '') ?></td>
                <td><?= $escape($entry['artist'] ?? '') ?></td>
                <td class="play-count-cell">
                  <button
                    type="button"
                    class="play-count-button"
                    data-dialog-target="<?= $escape($dialogId) ?>"
                    aria-haspopup="dialog"
                    aria-controls="<?= $escape($dialogId) ?>"
                    aria-label="<?= $escape(($entry['play_count'] ?? 0) . ' Spiele: Spielzeiten für ' . ($entry['title'] ?? '') . ' anzeigen') ?>"
                  ><?= $escape($entry['play_count'] ?? 0) ?></button>
                </td>
                <td><span class="status status-<?= $escape($entry['match_status'] ?? 'pending') ?>"><?= $escape($entry['match_status'] ?? 'pending') ?></span></td>
                <td class="action-cell">
                  <details class="context-menu">
                    <summary aria-label="Aktionen für <?= $escape($entry['title'] ?? '') ?>" title="Aktionen">&#8942;</summary>
                    <div class="context-menu-popover" role="menu">
                      <button type="button" class="context-menu-primary" role="menuitem" data-dialog-target="spotify-match-<?= $escape($entry['song_id'] ?? '') ?>">
                        <?= ($entry['match_status'] ?? '') === 'accepted' ? 'Zuordnung ändern' : 'Zuordnen' ?>
                      </button>
                      <button type="button" role="menuitem" data-dialog-target="ignore-song-<?= $escape($entry['song_id'] ?? '') ?>">Song ignorieren</button>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($ranking === []): ?>
              <tr><td colspan="6" class="empty-state">Keine geeigneten Spotify-Titel im Zeitraum.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($skipped !== []): ?>
          <div class="section-heading secondary-heading">
            <div><p class="eyebrow">Prüfliste</p><h2>Nicht im Sync-Ziel</h2></div>
            <span class="count-label"><?= $escape(count($skipped)) ?> übersprungen</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Airplay-Rang</th><th>Song</th><th>Künstler</th><th>Grund</th><th><span class="visually-hidden">Aktionen</span></th></tr></thead>
              <tbody>
              <?php foreach ($skipped as $entry): ?>
                <tr>
                  <td class="rank"><?= $escape($entry['airplay_rank'] ?? '') ?></td>
                  <td class="song-title"><?= $escape($entry['title'] ?? '') ?></td>
                  <td><?= $escape($entry['artist'] ?? '') ?></td>
                  <td><?= $escape($skipReasonLabels[$entry['skip_reason'] ?? ''] ?? 'Nicht verfügbar') ?></td>
                  <td class="action-cell">
                    <details class="context-menu">
                      <summary aria-label="Aktionen für <?= $escape($entry['title'] ?? '') ?>" title="Aktionen">&#8942;</summary>
                      <div class="context-menu-popover" role="menu">
                        <button type="button" class="context-menu-primary" role="menuitem" data-dialog-target="spotify-match-<?= $escape($entry['song_id'] ?? '') ?>">
                          <?= ($entry['match_status'] ?? '') === 'accepted' ? 'Zuordnung ändern' : 'Zuordnen' ?>
                        </button>
                        <button type="button" role="menuitem" data-dialog-target="ignore-song-<?= $escape($entry['song_id'] ?? '') ?>">Song ignorieren</button>
                      </div>
                    </details>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php foreach ($visibleEntries as $index => $entry): ?>
          <?php
          $dialogId = 'play-history-' . (int) ($entry['song_id'] ?? $index);
          $playTimes = is_array($entry['play_times'] ?? null) ? $entry['play_times'] : [];
          $playCount = (int) ($entry['play_count'] ?? 0);
          $playCountLabel = $playCount === 1 ? '1 Ausstrahlung' : $playCount . ' Ausstrahlungen';
          ?>
          <dialog class="app-dialog play-history-dialog" id="<?= $escape($dialogId) ?>" aria-labelledby="<?= $escape($dialogId) ?>-title">
            <div class="dialog-heading">
              <div>
                <p class="eyebrow">Spielzeiten</p>
                <h3 id="<?= $escape($dialogId) ?>-title"><?= $escape($playCountLabel) ?></h3>
              </div>
              <form method="dialog">
                <button type="submit" class="dialog-close" aria-label="Schliessen" title="Schliessen">&times;</button>
              </form>
            </div>
            <p class="dialog-song">
              <strong><?= $escape($entry['title'] ?? '') ?></strong>
              <span><?= $escape($entry['artist'] ?? '') ?></span>
            </p>
            <?php if ($playTimes !== []): ?>
              <ol class="play-time-list">
                <?php foreach ($playTimes as $playTime): ?>
                  <?php if (is_array($playTime)): ?>
                    <li><time datetime="<?= $escape($playTime['datetime'] ?? '') ?>"><?= $escape($playTime['label'] ?? '') ?> Uhr</time></li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ol>
            <?php else: ?>
              <p class="empty-state">Keine Spielzeiten verfügbar.</p>
            <?php endif; ?>
          </dialog>

          <?php $returnTo = '/admin/playlists/' . (int) ($playlist['id'] ?? 0); require __DIR__ . '/_spotify-match-dialog.php'; ?>

          <?php $ignoreDialogId = 'ignore-song-' . (int) ($entry['song_id'] ?? $index); ?>
          <dialog class="app-dialog ignore-song-dialog" id="<?= $escape($ignoreDialogId) ?>" aria-labelledby="<?= $escape($ignoreDialogId) ?>-title">
            <div class="dialog-heading">
              <div><p class="eyebrow">Playlist-Regel</p><h3 id="<?= $escape($ignoreDialogId) ?>-title">Song ignorieren</h3></div>
              <button type="button" class="dialog-close" data-dialog-close aria-label="Schliessen" title="Schliessen">&times;</button>
            </div>
            <form method="post" action="/admin/ignored-songs" class="ignore-song-form" data-ignore-form data-playlist-name="<?= $escape($playlist['name'] ?? '') ?>">
              <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
              <input type="hidden" name="song_id" value="<?= $escape($entry['song_id'] ?? '') ?>">
              <input type="hidden" name="playlist_id" value="<?= $escape($playlist['id'] ?? '') ?>">
              <input type="hidden" name="source_playlist_id" value="<?= $escape($playlist['id'] ?? '') ?>">
              <p class="dialog-song"><strong><?= $escape($entry['title'] ?? '') ?></strong><span><?= $escape($entry['artist'] ?? '') ?></span></p>
              <fieldset class="scope-selector">
                <legend>Bereich</legend>
                <label><input type="radio" name="scope" value="playlist" checked><span>Diese Playlist</span></label>
                <label><input type="radio" name="scope" value="global"><span>Global</span></label>
              </fieldset>
              <p class="scope-impact" data-scope-impact>Gilt nur für „<?= $escape($playlist['name'] ?? '') ?>“.</p>
              <label for="reason-<?= $escape($entry['song_id'] ?? '') ?>">Grund <span class="optional-label">optional</span></label>
              <textarea id="reason-<?= $escape($entry['song_id'] ?? '') ?>" name="reason" maxlength="500" rows="4" data-character-input></textarea>
              <p class="character-count" data-character-count>0 / 500</p>
              <p class="sync-note">Spotify wird beim nächsten Sync aktualisiert.</p>
              <div class="dialog-actions">
                <button type="button" class="button button-secondary" data-dialog-close>Abbrechen</button>
                <button type="submit" class="button button-primary" data-ignore-submit>Song ignorieren</button>
              </div>
            </form>
          </dialog>
        <?php endforeach; ?>
      </section>
    </div>
  </main>
</body>
</html>
