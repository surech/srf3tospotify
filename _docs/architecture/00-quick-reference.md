# Quick Reference

**Project:** SRF3ToSpotify
**Last updated:** 2026-09-03
**Status:** DRAFT

| Version | Date | Author | Change summary |
| --- | --- | --- | --- |
| 0.1 | 2026-08-26 | GitHub Copilot | Initial greenfield draft |
| 0.2 | 2026-09-03 | GitHub Copilot | Added managed Spotify playlist covers |

| Key | Decision |
| --- | --- |
| Primary stack | PHP 8.2+, Composer, server-rendered HTML |
| Runtime | Framework-free modular monolith, HTTP and CLI entry points |
| Persistence | MariaDB through PDO, UTC timestamps |
| Local development | Docker Compose with Apache/PHP and MariaDB |
| Production deployment | Built artifact including `vendor/`, transferred by FTP |
| Scheduling | Hoster CLI cron preferred; authenticated HTTP cron fallback |
| External systems | SRF Integration Layer and Spotify Web API |

## Core Decisions

- One deployable application because shared hosting provides neither long-running workers nor service orchestration.
- Native PHP cURL and PDO keep production dependencies small and portable.
- Every SRF play is idempotent through a SHA-256 event key containing channel, UTC start time, normalized artist/title, and duration.
- Spotify matching is cached per logical song, reviewable, and manually overridable.
- Playlist synchronization replaces the managed ranking instead of appending plays, preventing duplicates and stale entries.
- Playlist synchronization uploads the bundled cover assigned to each managed playlist.
- Credentials and encryption keys remain outside the public document root and outside version control.

## Assumptions

- **A-001:** Production supports PHP 8.2 or newer with cURL, GD, JSON, mbstring, OpenSSL, PDO and PDO MySQL.
- **A-002:** Production permits outbound HTTPS to SRF and Spotify.
- **A-003:** Initial playlist policy is top 50 unique songs from the last 30 complete Europe/Zurich calendar days, public playlist, count descending with most recent play as tie-breaker.
- **A-004:** Single-owner administration is sufficient; no user registration or multi-tenancy.

## Open Gaps

- [GAP-001: Hosting capabilities](../gaps/GAP-001-hosting-capabilities.md)
- [GAP-002: Playlist policy](../gaps/GAP-002-playlist-policy.md)
- [GAP-003: Spotify account and app](../gaps/GAP-003-spotify-account.md)