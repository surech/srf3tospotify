<?php
$playlist = is_array($playlist ?? null) ? $playlist : [];
$ranking = is_array($ranking ?? null) ? $ranking : [];
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
      <div class="page-width playlist-detail-heading">
        <?php if (is_string($playlist['cover_url'] ?? null)): ?>
          <img class="playlist-cover playlist-detail-cover" src="<?= $escape($playlist['cover_url']) ?>" alt="">
        <?php else: ?>
          <span class="playlist-cover playlist-cover-placeholder playlist-detail-cover" aria-hidden="true">SRF<span>3</span></span>
        <?php endif; ?>
        <div class="playlist-detail-copy">
          <a class="back-link" href="/">Zur Übersicht</a>
          <p class="eyebrow">Playlist</p>
          <h1><?= $escape($playlist['name'] ?? '') ?></h1>
          <p class="playlist-description"><?= $escape($playlist['description'] ?? '') ?></p>
        </div>
      </div>
    </section>

    <div class="page-width content-stack">
      <section aria-label="Songs der Playlist">
        <p class="count-label playlist-entry-count"><?= $escape(count($ranking)) ?> Einträge</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Song</th><th>Künstler</th><th>Spiele</th><th>Spotify</th></tr></thead>
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
              </tr>
            <?php endforeach; ?>
            <?php if ($ranking === []): ?>
              <tr><td colspan="5" class="empty-state">Noch keine Ausstrahlungen im Zeitraum.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php foreach ($ranking as $index => $entry): ?>
          <?php
          $dialogId = 'play-history-' . (int) ($entry['song_id'] ?? $index);
          $playTimes = is_array($entry['play_times'] ?? null) ? $entry['play_times'] : [];
          $playCount = (int) ($entry['play_count'] ?? 0);
          $playCountLabel = $playCount === 1 ? '1 Ausstrahlung' : $playCount . ' Ausstrahlungen';
          ?>
          <dialog class="play-history-dialog" id="<?= $escape($dialogId) ?>" aria-labelledby="<?= $escape($dialogId) ?>-title">
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
        <?php endforeach; ?>
      </section>
    </div>
  </main>
</body>
</html>
