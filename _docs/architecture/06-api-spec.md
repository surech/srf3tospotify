# API Specification

The web surface is a server-rendered owner dashboard. JSON responses are used for action results and cron integration; this is not a public API.

## Authentication

- Owner routes require an authenticated PHP session backed by `ADMIN_PASSWORD_HASH`.
- State-changing owner routes require same-site secure cookies and a CSRF token.
- Cron HTTP fallback requires `Authorization: Bearer <CRON_TOKEN>` and accepts no token in the URL.
- OAuth callback validates a one-time state value stored in the owner session.

## Routes

| Method | Path | Authorization | Contract |
| --- | --- | --- | --- |
| `GET` | `/login` | Public | Login form |
| `POST` | `/login` | Public + CSRF | Verify owner password and rotate session ID |
| `POST` | `/logout` | Session + CSRF | Destroy owner session |
| `GET` | `/` | Session | Status, recent runs, ranking and unresolved matches |
| `POST` | `/actions/import` | Session + CSRF | Body: `from_date`, `to_date`; synchronous import result |
| `POST` | `/actions/sync` | Session + CSRF | Build and synchronize all configured playlist rankings |
| `POST` | `/matches/{songId}` | Session + CSRF | Body: Spotify track URL/ID or `rejected`; save manual override |
| `GET` | `/spotify/authorize` | Session | Redirect to Spotify Authorization Code Flow |
| `GET` | `/spotify/callback` | Session + OAuth state | Exchange code and store encrypted tokens |
| `POST` | `/internal/cron/import` | Bearer token | Import previous complete Europe/Zurich day |
| `POST` | `/internal/cron/sync` | Bearer token | Synchronize all configured playlist rankings |
| `POST` | `/internal/maintenance/migrate` | Bearer token | Apply pending idempotent database migrations after FTP deployment |
| `GET` | `/health` | Public | `200` with status and non-reversible diagnostics for the loaded admin password hash; never returns the full hash |

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
  "total_track_count": 100,
  "total_unresolved_count": 0,
  "playlists": [
    {
      "name": "SRF 3 - Top 50",
      "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f790",
      "playlist_id": "spotify-top-50",
      "snapshot_id": "snapshot-top-50",
      "track_count": 50,
      "unresolved_count": 0
    },
    {
      "name": "SRF 3 - Der Morgen",
      "correlation_id": "0198e7d8-4f23-7b42-a5d2-7a64dd91f791",
      "playlist_id": "spotify-morning",
      "snapshot_id": "snapshot-morning",
      "track_count": 50,
      "unresolved_count": 0
    }
  ]
}
```

- Browser action validation errors return `422`.
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