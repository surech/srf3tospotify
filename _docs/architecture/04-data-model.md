# Data Model

## Entities

| Entity | Purpose | Lifecycle |
| --- | --- | --- |
| `radio_channel` | SRF channel identity and Europe/Zurich source timezone | Configured, enabled/disabled |
| `song` | Logical artist/title identity used for aggregation and Spotify matching | Created on first play, retained |
| `play` | One broadcast event normalized to UTC | Imported idempotently, retained |
| `import_run` | Auditable import attempt and counts | Running, succeeded, failed |
| `spotify_match` | Cached automatic or manual mapping from logical song to Spotify track | Pending, accepted, review; rejected is retained only as a legacy migration value |
| `oauth_token` | Encrypted Spotify refresh/access token material | Active, refreshed, revoked |
| `playlist` | Managed Spotify playlist and ranking policy | Unconfigured, active |
| `sync_run` | Auditable desired playlist snapshot and result | Running, succeeded, failed |
| `sync_run_item` | Ordered desired track list for a synchronization | Immutable with its run |
| `song_ignore_rule` | Historized global or playlist-specific exclusion period for one logical song | Active until manually reactivated; then immutable history |

## Keys and Constraints

- `song.identity_hash`: SHA-256 of Unicode case-folded, whitespace-collapsed artist and title; unique.
- `play.event_hash`: SHA-256 of channel ID, exact UTC play timestamp, normalized artist/title and duration; unique.
- Exact play time is mandatory in the event key so repeated broadcasts of the same song remain separate events.
- `spotify_match.song_id`: unique, ensuring one current mapping per logical song.
- A manual `review` match has no Spotify track fields and blocks later automatic acceptance until the owner selects a track.
- `sync_run_item`: unique by `(sync_run_id, spotify_track_id)` to prevent duplicate playlist items.
- `song_ignore_rule`: at most one active period per `(song_id, global-or-playlist scope)`; inactive periods remain retained.
- A null `song_ignore_rule.playlist_id` denotes a global rule that also applies to future playlists.
- Foreign keys use InnoDB and reject orphaned operational data.

## ERD

```mermaid
erDiagram
    RADIO_CHANNEL ||--o{ PLAY : broadcasts
    SONG ||--o{ PLAY : identifies
    IMPORT_RUN ||--o{ PLAY : imports
    SONG ||--o| SPOTIFY_MATCH : maps
    PLAYLIST ||--o{ SYNC_RUN : records
    SYNC_RUN ||--o{ SYNC_RUN_ITEM : contains
    SONG ||--o{ SYNC_RUN_ITEM : ranks
    SPOTIFY_MATCH ||--o{ SYNC_RUN_ITEM : resolves
    SONG ||--o{ SONG_IGNORE_RULE : excludes
    PLAYLIST ||--o{ SONG_IGNORE_RULE : scopes

    RADIO_CHANNEL {
        bigint id PK
        string source_channel_id UK
        string name
        string timezone
        boolean enabled
    }
    SONG {
        bigint id PK
        binary identity_hash UK
        string artist
        string title
        string normalized_artist
        string normalized_title
    }
    PLAY {
        bigint id PK
        binary event_hash UK
        bigint radio_channel_id FK
        bigint song_id FK
        bigint import_run_id FK nullable
        datetime played_at_utc
        smallint source_offset_minutes
        integer duration_ms
        boolean was_playing_now
    }
    IMPORT_RUN {
        bigint id PK
        string correlation_id UK
        string trigger_type
        datetime range_from_utc
        datetime range_to_utc
        string status
        integer received_count
        integer inserted_count
        integer duplicate_count
        text error_summary
    }
    SPOTIFY_MATCH {
        bigint id PK
        bigint song_id FK
        string spotify_track_id
        string spotify_uri
        string match_source
        decimal confidence
        string status
        datetime checked_at
    }
    OAUTH_TOKEN {
        bigint id PK
        string provider UK
        blob refresh_token_ciphertext
        blob access_token_ciphertext
        datetime access_token_expires_at
        datetime updated_at
    }
    PLAYLIST {
        bigint id PK
        string spotify_playlist_id UK
        string name
        integer ranking_days
        integer max_tracks
        integer target_tracks nullable
        boolean weekdays_only
        integer local_start_minute nullable
        integer local_end_minute nullable
        datetime fixed_from_utc nullable
        datetime fixed_to_utc nullable
        boolean public
    }
    SYNC_RUN {
        bigint id PK
        bigint playlist_id FK
        string correlation_id UK
        string status
        datetime window_from_utc
        datetime window_to_utc
        string spotify_snapshot_id
        integer requested_count
        integer track_count
        integer ignored_count
        integer unresolved_count
        integer duplicate_track_count
        text error_summary
    }
    SYNC_RUN_ITEM {
        bigint sync_run_id PK,FK
        integer position PK
        bigint song_id FK
        bigint spotify_match_id FK
        string spotify_track_id
        integer play_count
    }
    SONG_IGNORE_RULE {
        bigint id PK
        bigint song_id FK
        bigint playlist_id FK nullable
        string reason nullable
        datetime ignored_at
        datetime reactivated_at nullable
    }
```

## Storage Rules

- MariaDB connection and session timezone are forced to UTC.
- Local calendar boundaries are calculated with `Europe/Zurich` before conversion to UTC; this preserves 23- and 25-hour daylight-saving days.
- SRF timestamp offset reconstructs the local weekday and minute for filtered rankings without relying on MariaDB timezone tables.
- Null local start/end minutes mean all-day ranking; configured ranges include the start minute and exclude the end minute.
- Null fixed UTC bounds select a rolling calendar window; configured bounds select one immutable inclusive/exclusive event window.
- `playlist.max_tracks` caps candidate output. Nullable `target_tracks` distinguishes fixed-size playlists from policies that publish every eligible song up to the cap.
- Ignore reasons are optional and limited to 500 characters. Reactivation timestamps close a rule period; rows are never reopened or overwritten.
- Effective exclusions resolve the ignored song's current accepted Spotify track ID at target-calculation time, preventing aliases of the same concrete track while allowing distinct live or remix track IDs.
- Synchronization diagnostics persist requested and actual counts plus ignored, missing-match and duplicate-track causes for each playlist run.
- OAuth token ciphertext uses authenticated encryption; the encryption key comes from an environment variable and is never stored in MariaDB.
- Raw upstream JSON is not retained by default; sanitized fixture samples belong only in tests.
- Cleanup removes completed run metadata after 90 days. `play.import_run_id` becomes null through `ON DELETE SET NULL`; broadcast history remains intact.