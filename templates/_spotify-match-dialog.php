<?php
$songId = (int) ($entry['song_id'] ?? 0);
$matchDialogId = 'spotify-match-' . $songId;
$matchStatus = (string) ($entry['match_status'] ?? $entry['status'] ?? 'pending');
$isAcceptedMatch = $matchStatus === 'accepted';
$originalTitle = (string) ($entry['title'] ?? '');
$originalArtist = (string) ($entry['artist'] ?? '');
$searchArtist = trim(preg_replace('/\s*\(CH\)\s*/iu', ' ', $originalArtist) ?? $originalArtist);
$currentTrackId = is_string($entry['spotify_track_id'] ?? null) ? $entry['spotify_track_id'] : '';
$currentTrackTitle = is_string($entry['spotify_title'] ?? null) ? $entry['spotify_title'] : '';
$currentTrackArtist = is_string($entry['spotify_artist'] ?? null) ? $entry['spotify_artist'] : '';
$currentTrackDuration = (int) ($entry['spotify_duration_ms'] ?? 0);
$currentTrackLabel = $isAcceptedMatch ? 'Aktuelle Zuordnung' : 'Bisheriger Vorschlag';
$formatDuration = static function (int $durationMs): string {
    $seconds = (int) round($durationMs / 1000);

    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};
?>
<dialog
  class="app-dialog spotify-match-dialog"
  id="<?= $escape($matchDialogId) ?>"
  aria-labelledby="<?= $escape($matchDialogId) ?>-title"
  data-spotify-match-dialog
  data-current-track-id="<?= $escape($currentTrackId) ?>"
  data-current-track-label="<?= $escape($currentTrackLabel) ?>"
>
  <div class="dialog-heading">
    <div>
      <p class="eyebrow">Spotify-Zuordnung</p>
      <h3 id="<?= $escape($matchDialogId) ?>-title"><?= $isAcceptedMatch ? 'Zuordnung ändern' : 'Track zuordnen' ?></h3>
    </div>
    <button type="button" class="dialog-close" data-dialog-close aria-label="Schliessen" title="Schliessen">&times;</button>
  </div>

  <div class="spotify-match-body">
    <section class="source-song" aria-label="SRF-Originaldaten">
      <span class="source-label">SRF-Original</span>
      <strong><?= $escape($originalTitle) ?></strong>
      <span><?= $escape($originalArtist) ?></span>
    </section>
    <p class="mapping-scope">Die Spotify-Zuordnung gilt für diesen SRF-Song in allen Playlists.</p>

    <?php if ($currentTrackId !== ''): ?>
      <section class="current-match" aria-label="<?= $escape($currentTrackLabel) ?>">
        <div>
          <span class="source-label"><?= $escape($currentTrackLabel) ?></span>
          <strong><?= $escape($currentTrackTitle !== '' ? $currentTrackTitle : $currentTrackId) ?></strong>
          <?php if ($currentTrackArtist !== ''): ?><span><?= $escape($currentTrackArtist) ?></span><?php endif; ?>
          <?php if ($currentTrackDuration > 0): ?><span><?= $escape($formatDuration($currentTrackDuration)) ?> Min.</span><?php endif; ?>
        </div>
        <a href="https://open.spotify.com/track/<?= $escape($currentTrackId) ?>" target="_blank" rel="noreferrer">In Spotify öffnen</a>
      </section>
    <?php endif; ?>

    <form class="spotify-search-form" data-spotify-search-form>
      <div class="search-fields">
        <label>Künstler<input name="artist" value="<?= $escape($searchArtist) ?>" maxlength="200"></label>
        <label>Songtitel<input name="title" value="<?= $escape($originalTitle) ?>" maxlength="200"></label>
      </div>
      <button type="submit" class="button button-spotify" data-spotify-search-submit>Suchen</button>
    </form>

    <p class="spotify-search-message" data-spotify-search-message role="status" aria-live="polite"></p>
    <div class="spotify-results" data-spotify-results role="radiogroup" aria-label="Spotify-Suchergebnisse"></div>
    <button type="button" class="button button-secondary load-more-button" data-spotify-load-more hidden>Mehr laden</button>

    <form method="post" action="/matches/<?= $escape($songId) ?>" class="selection-form" data-spotify-selection-form>
      <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
      <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
      <input type="hidden" name="track" value="" data-selected-track>
      <button type="submit" class="button button-primary" data-assign-track disabled>Track zuordnen</button>
    </form>

    <details class="manual-track-fallback">
      <summary>Spotify-URL oder Track-ID verwenden</summary>
      <form method="post" action="/matches/<?= $escape($songId) ?>" class="manual-track-form">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
        <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
        <label for="manual-track-<?= $escape($songId) ?>">Spotify-URL oder Track-ID</label>
        <div>
          <input id="manual-track-<?= $escape($songId) ?>" name="track" required>
          <button type="submit" class="button button-secondary">Zuordnen</button>
        </div>
      </form>
    </details>

    <?php if ($isAcceptedMatch): ?>
      <section class="dialog-action-section">
        <h4>Zuordnung aufheben</h4>
        <button type="button" class="button button-quiet danger-action" data-confirm-reveal aria-controls="reset-confirm-<?= $escape($songId) ?>">Zuordnung aufheben</button>
        <div class="inline-confirmation" id="reset-confirm-<?= $escape($songId) ?>" hidden>
          <p>Der Song bleibt für die manuelle Prüfung offen und wird nicht automatisch neu zugeordnet.</p>
          <div class="dialog-actions">
            <button type="button" class="button button-secondary" data-confirm-cancel>Abbrechen</button>
            <form method="post" action="/matches/<?= $escape($songId) ?>">
              <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
              <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
              <input type="hidden" name="action" value="reset">
              <button type="submit" class="button button-primary">Zuordnung aufheben</button>
            </form>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="dialog-action-section ignore-match-section">
      <h4>Song global ignorieren</h4>
      <form method="post" action="/ignored-songs" data-global-ignore-form>
        <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
        <input type="hidden" name="song_id" value="<?= $escape($songId) ?>">
        <input type="hidden" name="scope" value="global">
        <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
        <label for="match-ignore-reason-<?= $escape($songId) ?>">Grund <span class="optional-label">optional</span></label>
        <textarea id="match-ignore-reason-<?= $escape($songId) ?>" name="reason" maxlength="500" rows="3" data-character-input></textarea>
        <p class="character-count" data-character-count>0 / 500</p>
        <p class="scope-impact">Gilt für alle bestehenden und zukünftigen Playlists. Eine vorhandene Zuordnung bleibt gespeichert.</p>
        <button type="button" class="button button-quiet danger-action" data-confirm-reveal aria-controls="ignore-confirm-<?= $escape($songId) ?>">Global ignorieren</button>
        <div class="inline-confirmation" id="ignore-confirm-<?= $escape($songId) ?>" hidden>
          <p>Song wirklich in allen Playlists ignorieren?</p>
          <div class="dialog-actions">
            <button type="button" class="button button-secondary" data-confirm-cancel>Abbrechen</button>
            <button type="submit" class="button button-primary">Global ignorieren</button>
          </div>
        </div>
      </form>
    </section>
  </div>
</dialog>