# System Overview

## Purpose

SRF3ToSpotify imports the play history of one or more SRF radio channels, stores each broadcast event, presents play-frequency rankings, matches logical radio songs to Spotify tracks, and keeps configured Spotify playlists aligned with their ranking policies.

## Actors

| Actor | Goal |
| --- | --- |
| Owner | Configure Spotify, run imports/synchronizations, inspect rankings, correct matches |
| Scheduler | Execute unattended imports and playlist synchronization |
| SRF Integration Layer | Supply radio play events as JSON |
| Spotify | Authorize the owner, search tracks, and manage the target playlists |

## Scope

### Included

- Manual date-range import through a protected web dashboard and CLI.
- Automatic import through hoster cron.
- Idempotent storage and re-import.
- Rankings by play count for a configurable period.
- Automatic Spotify matching with confidence and manual correction.
- Creation or update of all configured managed Spotify playlists.
- Operational status, run history and structured logs.

### Excluded

- Audio playback or storage.
- Public multi-user service.
- Mobile application.
- Machine-learning matching.
- Real-time streaming ingestion.

## Constraints

- Production offers PHP and MariaDB on shared hosting; no application server or worker process is assumed.
- Deployment uses FTP, so the release artifact must contain runtime dependencies.
- Spotify OAuth requires an HTTPS callback URL matching the Spotify dashboard configuration exactly.
- The SRF endpoint has no published stability guarantee in the supplied requirements.

## Non-Functional Targets

| Concern | Target |
| --- | --- |
| Import performance | One normal broadcast day, up to 500 events, completes within 30 seconds excluding upstream outage retries |
| Dashboard performance | Ranking and status pages respond within 1 second at p95 for 500,000 stored plays |
| Synchronization | Both default playlists complete within 2 minutes when Spotify is not rate-limiting |
| Reliability | Repeating any import or sync produces no duplicate play or playlist item |
| Recovery | Failed runs retain previous playlist content and can be retried without manual database repair |
| Availability | Best effort; one successful daily import within 24 hours is sufficient |
| Retention | Play history retained until owner deletion; operational logs and run details retained for 90 days |
| Privacy | No listener data; Spotify tokens encrypted at rest; secrets never logged |
| Observability | Every run has correlation ID, status, counts, duration and sanitized error details |

## Risks

- SRF payload or endpoint changes can stop imports; strict schema validation must fail visibly.
- A response equal to `pageSize` may be truncated because no pagination metadata was observed.
- Search-based Spotify matching can select remasters, covers or live recordings incorrectly.
- Shared-hosting limits can prevent cron, outbound HTTPS, URL rewriting or secure secret placement.