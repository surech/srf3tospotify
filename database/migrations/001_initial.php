<?php

declare(strict_types=1);

return [
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS radio_channels (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_channel_id CHAR(36) NOT NULL,
            name VARCHAR(190) NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Zurich',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_radio_channels_source (source_channel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS songs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identity_hash BINARY(32) NOT NULL,
            artist VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            normalized_artist VARCHAR(255) NOT NULL,
            normalized_title VARCHAR(255) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_songs_identity (identity_hash),
            KEY ix_songs_normalized (normalized_artist, normalized_title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS import_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            correlation_id CHAR(36) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            range_from_utc DATETIME(6) NOT NULL,
            range_to_utc DATETIME(6) NOT NULL,
            status VARCHAR(32) NOT NULL,
            received_count INT UNSIGNED NOT NULL DEFAULT 0,
            inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_summary VARCHAR(1000) NULL,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            finished_at DATETIME(6) NULL,
            UNIQUE KEY uq_import_runs_correlation (correlation_id),
            KEY ix_import_runs_started (started_at),
            KEY ix_import_runs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS plays (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_hash BINARY(32) NOT NULL,
            radio_channel_id BIGINT UNSIGNED NOT NULL,
            song_id BIGINT UNSIGNED NOT NULL,
            import_run_id BIGINT UNSIGNED NOT NULL,
            played_at_utc DATETIME(6) NOT NULL,
            source_offset_minutes SMALLINT NOT NULL,
            duration_ms INT UNSIGNED NOT NULL,
            was_playing_now TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_plays_event (event_hash),
            KEY ix_plays_played_song (played_at_utc, song_id),
            KEY ix_plays_song_played (song_id, played_at_utc),
            CONSTRAINT fk_plays_channel FOREIGN KEY (radio_channel_id) REFERENCES radio_channels (id),
            CONSTRAINT fk_plays_song FOREIGN KEY (song_id) REFERENCES songs (id),
            CONSTRAINT fk_plays_import FOREIGN KEY (import_run_id) REFERENCES import_runs (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS spotify_matches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            song_id BIGINT UNSIGNED NOT NULL,
            spotify_track_id VARCHAR(64) NULL,
            spotify_uri VARCHAR(128) NULL,
            spotify_title VARCHAR(255) NULL,
            spotify_artist VARCHAR(255) NULL,
            duration_ms INT UNSIGNED NULL,
            match_source VARCHAR(32) NOT NULL DEFAULT 'automatic',
            confidence DECIMAL(5,4) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            checked_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_spotify_matches_song (song_id),
            KEY ix_spotify_matches_status (status),
            CONSTRAINT fk_spotify_matches_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL,
            access_token_ciphertext MEDIUMBLOB NOT NULL,
            refresh_token_ciphertext MEDIUMBLOB NOT NULL,
            access_token_expires_at DATETIME(6) NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_oauth_tokens_provider (provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS playlists (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            spotify_playlist_id VARCHAR(64) NULL,
            spotify_owner_id VARCHAR(128) NULL,
            name VARCHAR(255) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            ranking_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            max_tracks SMALLINT UNSIGNED NOT NULL DEFAULT 50,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_playlists_spotify (spotify_playlist_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS sync_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            playlist_id BIGINT UNSIGNED NOT NULL,
            correlation_id CHAR(36) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            window_from_utc DATETIME(6) NOT NULL,
            window_to_utc DATETIME(6) NOT NULL,
            spotify_snapshot_id VARCHAR(255) NULL,
            unresolved_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_summary VARCHAR(1000) NULL,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            finished_at DATETIME(6) NULL,
            UNIQUE KEY uq_sync_runs_correlation (correlation_id),
            KEY ix_sync_runs_started (started_at),
            CONSTRAINT fk_sync_runs_playlist FOREIGN KEY (playlist_id) REFERENCES playlists (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS sync_run_items (
            sync_run_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            song_id BIGINT UNSIGNED NOT NULL,
            spotify_match_id BIGINT UNSIGNED NOT NULL,
            spotify_track_id VARCHAR(64) NOT NULL,
            play_count INT UNSIGNED NOT NULL,
            PRIMARY KEY (sync_run_id, position),
            UNIQUE KEY uq_sync_run_track (sync_run_id, spotify_track_id),
            CONSTRAINT fk_sync_items_run FOREIGN KEY (sync_run_id) REFERENCES sync_runs (id) ON DELETE CASCADE,
            CONSTRAINT fk_sync_items_song FOREIGN KEY (song_id) REFERENCES songs (id),
            CONSTRAINT fk_sync_items_match FOREIGN KEY (spotify_match_id) REFERENCES spotify_matches (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    <<<'SQL'
        INSERT INTO radio_channels (source_channel_id, name, timezone)
        VALUES ('dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f', 'SRF 3', 'Europe/Zurich')
        ON DUPLICATE KEY UPDATE name = VALUES(name), timezone = VALUES(timezone)
        SQL,
];