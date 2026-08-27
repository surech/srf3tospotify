# Testing Strategy

## Test Layers

| Layer | Scope | Required checks |
| --- | --- | --- |
| Unit | Normalization, event hashes, local-day windows, ranking, scoring, error mapping | Fast tests without network or database |
| Database integration | Migrations, upserts, constraints, rankings, token storage, advisory locks | Real MariaDB container |
| HTTP adapter | SRF and Spotify request/response handling | Local deterministic fake server or transport fake; no live API dependency |
| Application integration | Import and sync services with real DB plus fake upstreams | Success, duplicate retry, truncation split, rate limit, partial failure |
| Smoke | Built Apache container and production artifact | Login, dashboard, manual fixture import, health endpoint |
| Live acceptance | Owner-controlled SRF and Spotify accounts | One historical import, OAuth, reviewed match and private playlist sync |

## Critical Scenarios

- Re-importing the same SRF day inserts zero additional plays.
- Two identical artist/title broadcasts at different times produce two plays.
- The Europe/Zurich spring day spans 23 hours and autumn day spans 25 hours without loss or duplication.
- A 500-item SRF response triggers interval splitting and boundary duplicates are removed by the event key.
- Malformed JSON or a missing `artist.name` fails the run without partial writes.
- Parallel web and cron imports result in one active run and one lock-conflict response.
- Spotify candidate threshold and runner-up margin route ambiguous versions to review.
- Manual Spotify override survives later matching and synchronization.
- Desired playlist contains unique tracks in deterministic ranking order.
- Spotify `429` honors `Retry-After` and leaves a retryable run.
- Secrets, access tokens and refresh tokens never appear in rendered pages or logs.

## Quality Gates

- PHPUnit suite passes in Docker.
- PHPStan reports no errors at the configured strictness level.
- Formatting check passes without modifying files.
- Database migrations apply from empty schema and are repeatably detectable.
- Production artifact starts against the verified MariaDB version.
- No automatic browser screenshots or UI automation; visual checks remain manual unless explicitly requested.

## Fixtures

- Store minimal, anonymization-free SRF examples because the source contains only public play metadata.
- Replace real Spotify identifiers and all OAuth material with obvious test values.
- Capture schema variants deliberately; do not refresh fixtures silently when upstream changes.