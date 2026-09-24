# API Specification

The web surface consists of a server-rendered read-only homepage and a protected owner dashboard. No public JSON API exists.

## Authentication

- Owner routes under `/admin` require an authenticated PHP session backed by `ADMIN_PASSWORD_HASH`.
- The admin cookie is named `srf3spotify_admin_session`, scoped to `/admin`, `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS.
- Five failed logins per HMAC-keyed `REMOTE_ADDR` in 15 minutes are allowed; subsequent attempts return `429` with `Retry-After`.
- State-changing owner routes require same-site secure cookies and a CSRF token.
- Cron HTTP fallback requires `Authorization: Bearer <CRON_TOKEN>` and accepts no token in the URL.
- OAuth callback validates a one-time state value stored in the owner session.

## Routes

| Method | Path | Authorization | Contract |
| --- | --- | --- | --- |
| `GET` | `/` | Public, read-only | Public playlists and ordered tracks from latest eligible successful snapshots; `Cache-Control: no-cache` |
| `GET` | `/playlist-covers/{playlistId}` | Public, read-only | Current local PNG cover after publication eligibility check; otherwise `404` |
| `GET` | `/admin/login` | Public | Login form; validated `return_to` supports only read-only admin UI pages |
| `POST` | `/admin/login` | Public + CSRF | Verify owner password, enforce rate limit and rotate session ID |
| `POST` | `/admin/logout` | Session + CSRF | Destroy owner session and redirect to `/` |
| `GET` | `/admin` | Session | Status, recent runs, configured playlists and unresolved matches |
| `GET` | `/admin/playlists/{playlistId}` | Session | Next synchronization target, examined skipped candidates and playlist metadata |
| `GET` | `/admin/playlists/{playlistId}/cover` | Session | Configured PNG cover; `404` when unavailable |
| `GET` | `/admin/ignored-songs` | Session | Active ignored songs; query `history=1` includes closed periods |
| `POST` | `/admin/ignored-songs` | Session + CSRF | Create playlist or global ignore rule |
| `POST` | `/admin/ignored-songs/{ruleId}/reactivate` | Session + CSRF | Close one active ignore-rule period |
| `POST` | `/admin/actions/import` | Session + CSRF | Synchronous date-range import |
| `POST` | `/admin/actions/sync` | Session + CSRF | Synchronize all configured playlist targets |
| `GET` | `/admin/spotify/tracks/search` | Session | Validated 10-result CH-market search page |
| `POST` | `/admin/matches/{songId}` | Session + CSRF | Select or reset one Spotify match |
| `GET` | `/admin/spotify/authorize` | Session | Redirect to Spotify Authorization Code Flow |
| `GET` | `/admin/spotify/callback` | Session + OAuth state | Exchange code and store encrypted tokens |
| `POST` | `/internal/cron/import` | Bearer token | Import previous complete Europe/Zurich day |
| `POST` | `/internal/cron/sync` | Bearer token | Synchronize all configured playlist rankings |
| `POST` | `/internal/maintenance/migrate` | Bearer token | Apply pending idempotent database migrations after FTP deployment |
| `GET` | `/health` | Public | `200` with status and non-reversible diagnostics for the loaded admin password hash; never returns the full hash |

Old browser paths return `404` without redirects. Spotify search errors use `application/problem+json`; expired JSON sessions return `401`, missing Spotify authorization returns `409`, and public load failures return generic HTML `503` with a correlation ID.

The password-hash diagnostics contain the configuration source, length, algorithm, prefix, suffix and SHA-256 fingerprint. A valid bcrypt value has length `60`, algorithm `bcrypt` and prefix `$2y$10$`.

## Action Response

```json
{
  "status": "succeeded",
  "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f790",
  "counts": {
    "received": 330,
    "inserted": 330,
    "duplicates": 0
  }
}
```

## Error Response

```json
{
  "status": "failed",
  "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f790",
  "error": {
    "code": "UPSTREAM_SCHEMA_INVALID",
    "message": "SRF returned an unsupported response."
  }
}
```

## Synchronization Response

Top-level playlist identifiers and counts refer to the first configured playlist for compatibility. `total_*` counts aggregate all synchronized playlists; `playlists` contains each individual result.

```json
{
  "status": "succeeded",
  "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f790",
  "playlist_id": "spotify-top-50",
  "snapshot_id": "snapshot-top-50",
  "playlist_count": 2,
  "track_count": 50,
  "unresolved_count": 0,
  "has_warnings": false,
  "total_track_count": 100,
  "total_requested_count": 100,
  "total_unresolved_count": 0,
  "total_ignored_count": 0,
  "total_duplicate_track_count": 0,
  "playlists": [
    {
      "name": "SRF 3 - Top 50",
      "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f790",
      "playlist_id": "spotify-top-50",
      "snapshot_id": "snapshot-top-50",
      "requested_count": 50,
      "track_count": 50,
      "unresolved_count": 0,
      "ignored_count": 0,
      "duplicate_track_count": 0,
      "has_warning": false
    },
    {
      "name": "SRF 3 - Der Morgen",
      "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f791",
      "playlist_id": "spotify-morning",
      "snapshot_id": "snapshot-morning",
      "requested_count": 50,
      "track_count": 50,
      "unresolved_count": 0,
      "ignored_count": 0,
      "duplicate_track_count": 0,
      "has_warning": false
    }
  ]
}
```

- Browser action validation errors return `422`.
- Browser ignore actions redirect back to the source playlist or management page and expose a session flash message. They never trigger Spotify synchronization directly.
- Unknown or invalid playlist IDs return an HTML `404` page.
- Authentication failures return `401`; authorization/CSRF failures return `403`.
- Lock contention returns `409` with the active run correlation ID when available.
- Upstream or database failures return `502` or `503`; internal stack traces remain in protected logs only.

## CLI Contract

```text
php bin/console migrate
php bin/console import --from=YYYY-MM-DD --to=YYYY-MM-DD --trigger=manual
php bin/console sync --trigger=manual
php bin/console cleanup
php bin/console diagnostics
```

- Exit `0`: success.
- Exit `2`: invalid arguments or configuration.
- Exit `3`: lock already held.
- Exit `1`: operational failure.
- STDOUT contains a compact result; STDERR contains sanitized error context.