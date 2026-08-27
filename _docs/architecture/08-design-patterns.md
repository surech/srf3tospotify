# Key Design Patterns

## Modular Monolith

One PHP release owns imports, rankings, matching and synchronization. Internal module boundaries preserve testability without introducing unavailable infrastructure.

## Ports and Adapters

Application services consume interfaces for SRF, Spotify, clocks, locks and repositories. cURL and PDO implementations sit at the edge; tests replace them with deterministic fakes.

## Transaction Script with Domain Values

Each user-visible operation is a short application service transaction. Immutable date windows, normalized identities, candidates and result objects make validation explicit without a large object model.

## Database-Enforced Idempotency

Unique hashes are the final duplicate guard. Application-level pre-checks may optimize reporting but are never relied on for correctness.

## Build Desired State, Then Reconcile

Playlist synchronization first stores an ordered desired snapshot and then reconciles Spotify to it. This is safer than appending each imported play and supports deterministic retries.

## Cached Matching with Human Override

Spotify search runs once per logical song unless reset. Automatic confidence controls acceptance; manual selection or rejection has precedence and survives later syncs.

## Advisory Lock per Operation

MariaDB `GET_LOCK` serializes imports and synchronization across web and CLI processes. Lock names include environment and operation; connections release locks in `finally` blocks.

## Configuration and Secret Separation

Non-secret defaults are versioned. Credentials, password hashes, OAuth encryption key and cron token are injected from a non-public `.env` file or hoster environment.

## Decision Records

| Decision | Status | Evidence |
| --- | --- | --- |
| Framework-free PHP monolith | Proposed | Shared hosting supports only PHP and MariaDB; scope is single-owner |
| Docker Compose locally | Accepted | Docker and Compose verified locally on 2026-08-26 |
| UTC persistence with Europe/Zurich boundaries | Proposed | SRF timestamps include offsets and Swiss daylight saving applies |
| Full desired-playlist replacement | Proposed | Requirement is generation/update from ranking, not historical append |
| Encrypted OAuth tokens in MariaDB | Proposed | Long-running Authorization Code Flow requires refresh-token persistence |