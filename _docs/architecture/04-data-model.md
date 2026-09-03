# Data Model

## Entities

| Entity | Purpose | Lifecycle |
| --- | --- | --- |
| `radio_channel` | SRF channel identity and Europe/Zurich source timezone | Configured, enabled/disabled |
| `song` | Logical artist/title identity used for aggregation and Spotify matching | Created on first play, retained |
| `play` | One broadcast event normalized to UTC | Imported idempotently, retained |
| `import_run` | Auditable import attempt and counts | Running, succeeded, failed |
| `spotify_match` | Cached automatic or manual mapping from logical song to Spotify track | Pending, accepted, review, rejected |
| `oauth_token` | Encrypted Spotify refresh/access token material | Active, refreshed, revoked |
| `playlist` | Managed Spotify playlist and ranking policy | Unconfigured, active |
| `sync_run` | Auditable desired playlist snapshot and result | Running, succeeded, failed |
| `sync_run_item` | Ordered desired track list for a synchronization | Immutable with its run |

## Keys and Constraints

- `song.identity_hash`: SHA-256 of Unicode case-folded, whitespace-collapsed artist and title; unique.
- `play.event_hash`: SHA-256 of channel ID, exact UTC play timestamp, normalized artist/title and duration; unique.
- Exact play time is mandatory in the event key so repeated broadcasts of the same song remain separate events.
- `spotify_match.song_id`: unique, ensuring one current mapping per logical song.
- `sync_run_item`: unique by `(sync_run_id, spotify_track_id)` to prevent duplicate playlist items.
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
        boolean weekdays_only
        integer local_start_minute nullable
        integer local_end_minute nullable
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
```

## Storage Rules

- MariaDB connection and session timezone are forced to UTC.
- Local calendar boundaries are calculated with `Europe/Zurich` before conversion to UTC; this preserves 23- and 25-hour daylight-saving days.
- SRF timestamp offset reconstructs the local weekday and minute for filtered rankings without relying on MariaDB timezone tables.
- Null local start/end minutes mean all-day ranking; configured ranges include the start minute and exclude the end minute.
- OAuth token ciphertext uses authenticated encryption; the encryption key comes from an environment variable and is never stored in MariaDB.
- Raw upstream JSON is not retained by default; sanitized fixture samples belong only in tests.
- Cleanup removes completed run metadata after 90 days. `play.import_run_id` becomes null through `ON DELETE SET NULL`; broadcast history remains intact.