# Module Structure

## Dependency Rule

HTTP and CLI adapters call application services. Application services depend on domain values and repository/client interfaces. Infrastructure implements those interfaces. Domain code never imports web, CLI, PDO or cURL concerns.

## Proposed Modules

| Module | Responsibility | May depend on |
| --- | --- | --- |
| `Domain` | Play, song, ranking, match and run value objects; normalization and scoring rules | PHP standard library |
| `Application/Import` | Date-window import orchestration, schema checks, idempotent persistence | Domain, repository and SRF ports |
| `Application/Ranking` | Aggregate plays into deterministic rankings | Domain, repository ports |
| `Application/Spotify` | OAuth, matching review, ignored-song lifecycle, target selection and playlist synchronization orchestration | Domain, repositories, Spotify port |
| `Infrastructure/Database` | PDO connection, transactions, migrations, repositories and login-attempt throttling | Domain, application and web ports |
| `Infrastructure/Http` | cURL transport, SRF client and Spotify client | Application ports |
| `Infrastructure/Security` | Token encryption, session authentication and CSRF protection | PHP extensions, configuration |
| `Web` | Public read-only and protected `/admin` routes, lazy sessions, CSRF, HTML views and error mapping | Application services |
| `Cli` | Import, sync, migrate and cleanup commands | Application services |

## Planned Runtime Layout

```text
bin/                 CLI entry points
config/              non-secret configuration
database/            ordered SQL migrations
public/              document root and front controller
resources/           non-public static source assets such as playlist covers
src/                 PSR-4 application source
templates/           server-rendered HTML
tests/               unit and integration tests
var/                  non-public logs and temporary files
vendor/               Composer production dependencies in release artifact
```

## Entry Points

- `public/index.php`: only web-accessible PHP entry point.
- `bin/console`: CLI dispatcher for `migrate`, `import`, `sync`, `cleanup` and diagnostics.
- Both create the same application container and call the same services; manual and automatic behavior cannot diverge.

## Playlist Target Components

- `SongIgnoreService` validates reasons and opens or closes immutable ignore-rule periods.
- `SongIgnoreRepository` persists global and playlist-specific rules and resolves their currently accepted Spotify track IDs.
- `PlaylistTargetService` combines the complete policy-specific airplay ranking with one exclusion snapshot.
- `PlaylistTargetBuilder` walks candidates in ranking order until the configured target is full, skipping active exclusions, missing matches and duplicate Spotify track IDs.
- `PlaylistSyncService` and `DefaultWebOperations` consume the same target service so the playlist page previews the next synchronization result.
- `PlaylistRepository` reads the latest successful publication snapshot for the public homepage without calling Spotify.