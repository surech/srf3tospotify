<?php
$songs = is_array($songs ?? null) ? $songs : [];
$songCount = (int) ($song_count ?? 0);
$activeRuleCount = (int) ($active_rule_count ?? 0);
$includeHistory = ($include_history ?? false) === true;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ignorierte Songs · SRF3ToSpotify</title>
  <link rel="stylesheet" href="/assets/app.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
  <header class="topbar">
    <div class="page-width topbar-inner">
      <a href="/" class="brand-mark">SRF<span>3</span> · Spotify</a>
      <nav class="top-actions" aria-label="Kontoverwaltung">
        <a class="button button-secondary" href="/ignored-songs" aria-current="page">Ignorierte Songs</a>
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
      <div class="page-width section-heading summary-heading">
        <div>
          <a class="back-link" href="/">Zur Übersicht</a>
          <p class="eyebrow">Playlist-Regeln</p>
          <h1>Ignorierte Songs</h1>
        </div>
        <p class="last-run"><?= $escape($songCount) ?> Songs · <?= $escape($activeRuleCount) ?> aktive Regeln</p>
      </div>
    </section>

    <div class="page-width content-stack">
      <?php if (isset($flash) && is_string($flash) && $flash !== ''): ?>
        <p class="notice notice-<?= $escape(($flash_type ?? 'success') === 'warning' ? 'warning' : 'success') ?>" role="status"><?= $escape($flash) ?></p>
      <?php endif; ?>

      <div class="ignored-toolbar">
        <p class="count-label">Sortierung: zuletzt ignoriert</p>
        <form method="get" action="/ignored-songs">
          <label class="toggle-control">
            <input type="checkbox" name="history" value="1" data-auto-submit<?= $includeHistory ? ' checked' : '' ?>>
            Historie anzeigen
          </label>
        </form>
      </div>

      <section class="ignored-song-list" aria-label="Ignorierte Songs">
        <?php foreach ($songs as $song): ?>
          <?php
          $rules = is_array($song['rules'] ?? null) ? $song['rules'] : [];
          $specificPlaylists = is_array($song['active_specific_playlists'] ?? null)
              ? $song['active_specific_playlists']
              : [];
          $globalDialogId = 'ignore-global-' . (int) ($song['song_id'] ?? 0);
          ?>
          <article class="ignored-song-group">
            <header class="ignored-song-heading">
              <div>
                <h2><?= $escape($song['title'] ?? '') ?></h2>
                <p><?= $escape($song['artist'] ?? '') ?></p>
              </div>
              <?php if (($song['can_ignore_globally'] ?? false) === true): ?>
                <button type="button" class="button button-secondary" data-dialog-target="<?= $escape($globalDialogId) ?>">Global ignorieren</button>
              <?php endif; ?>
            </header>

            <div class="ignore-rule-list">
              <?php foreach ($rules as $rule): ?>
                <?php
                $isActive = ($rule['is_active'] ?? false) === true;
                $isGlobal = ($rule['is_global'] ?? false) === true;
                $confirmation = $isGlobal && $isActive && $specificPlaylists !== []
                    ? 'Der Song bleibt in folgenden Playlists ignoriert: ' . implode(', ', $specificPlaylists) . '. Global reaktivieren?'
                    : null;
                ?>
                <div class="ignore-rule<?= $isActive ? '' : ' ignore-rule-history' ?>">
                  <div class="ignore-rule-copy">
                    <p class="ignore-rule-meta">
                      <span class="scope-label"><?= $escape($isGlobal ? 'Global' : ($rule['playlist_name'] ?? 'Playlist')) ?></span>
                      <span>Ignoriert seit <?= $escape($rule['ignored_at'] ?? '') ?></span>
                      <?php if (!$isActive): ?><span>Reaktiviert am <?= $escape($rule['reactivated_at'] ?? '') ?></span><?php endif; ?>
                    </p>
                    <p class="ignore-reason"><?= $escape($rule['reason'] ?? 'Kein Grund angegeben') ?></p>
                  </div>
                  <?php if ($isActive): ?>
                    <form method="post" action="/ignored-songs/<?= $escape($rule['id'] ?? '') ?>/reactivate"<?= $confirmation === null ? '' : ' data-confirm="' . $escape($confirmation) . '"' ?>>
                      <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
                      <?php if ($includeHistory): ?><input type="hidden" name="return_history" value="1"><?php endif; ?>
                      <button type="submit" class="button button-quiet">Reaktivieren</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </article>

          <?php if (($song['can_ignore_globally'] ?? false) === true): ?>
            <dialog class="app-dialog ignore-song-dialog" id="<?= $escape($globalDialogId) ?>" aria-labelledby="<?= $escape($globalDialogId) ?>-title">
              <div class="dialog-heading">
                <div><p class="eyebrow">Alle Playlists</p><h3 id="<?= $escape($globalDialogId) ?>-title">Song global ignorieren</h3></div>
                <button type="button" class="dialog-close" data-dialog-close aria-label="Schliessen" title="Schliessen">&times;</button>
              </div>
              <form method="post" action="/ignored-songs" class="ignore-song-form">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
                <input type="hidden" name="song_id" value="<?= $escape($song['song_id'] ?? '') ?>">
                <input type="hidden" name="scope" value="global">
                <p class="dialog-song"><strong><?= $escape($song['title'] ?? '') ?></strong><span><?= $escape($song['artist'] ?? '') ?></span></p>
                <p class="scope-impact">Gilt für alle bestehenden und zukünftigen Playlists.</p>
                <label for="reason-global-<?= $escape($song['song_id'] ?? '') ?>">Grund <span class="optional-label">optional</span></label>
                <textarea id="reason-global-<?= $escape($song['song_id'] ?? '') ?>" name="reason" maxlength="500" rows="4" data-character-input></textarea>
                <p class="character-count" data-character-count>0 / 500</p>
                <div class="dialog-actions">
                  <button type="button" class="button button-secondary" data-dialog-close>Abbrechen</button>
                  <button type="submit" class="button button-primary">Global ignorieren</button>
                </div>
              </form>
            </dialog>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($songs === []): ?>
          <p class="empty-state"><?= $includeHistory ? 'Keine Ignorierregeln vorhanden.' : 'Keine Songs aktiv ignoriert.' ?></p>
        <?php endif; ?>
      </section>
    </div>
  </main>
</body>
</html>