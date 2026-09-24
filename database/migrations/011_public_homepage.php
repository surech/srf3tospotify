<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE sync_runs
            ADD COLUMN IF NOT EXISTS published_name VARCHAR(255) NULL AFTER spotify_snapshot_id,
            ADD COLUMN IF NOT EXISTS published_description VARCHAR(500) NULL AFTER published_name,
            ADD COLUMN IF NOT EXISTS published_spotify_playlist_id VARCHAR(64) NULL AFTER published_description,
            ADD COLUMN IF NOT EXISTS published_public TINYINT(1) NOT NULL DEFAULT 0 AFTER published_spotify_playlist_id,
            ADD INDEX IF NOT EXISTS ix_sync_runs_latest_success (playlist_id, status, id)
        SQL,
    <<<'SQL'
        ALTER TABLE sync_run_items
            ADD COLUMN IF NOT EXISTS spotify_title VARCHAR(255) NULL AFTER spotify_track_id,
            ADD COLUMN IF NOT EXISTS spotify_artist VARCHAR(255) NULL AFTER spotify_title
        SQL,
    <<<'SQL'
        UPDATE playlists
        SET description = CASE name
            WHEN 'SRF 3 - Top 50'
                THEN 'Die meistgespielten Songs auf SRF 3 aus den letzten 30 vollständigen Tagen.'
            WHEN 'SRF 3 - Der Morgen'
                THEN 'Die meistgespielten Songs auf SRF 3 aus den letzten 30 vollständigen Tagen, jeweils Montag bis Freitag von 06:00 bis 10:00 Uhr (Schweizer Zeit).'
            ELSE description
        END
        WHERE name IN ('SRF 3 - Top 50', 'SRF 3 - Der Morgen')
        SQL,
    <<<'SQL'
        UPDATE sync_runs sr
        INNER JOIN playlists p ON p.id = sr.playlist_id
        SET sr.published_name = p.name,
            sr.published_description = p.description,
            sr.published_spotify_playlist_id = p.spotify_playlist_id,
            sr.published_public = p.is_public
        WHERE sr.status = 'succeeded' AND sr.published_name IS NULL
        SQL,
    <<<'SQL'
        UPDATE sync_run_items sri
        INNER JOIN songs s ON s.id = sri.song_id
        LEFT JOIN spotify_matches sm ON sm.id = sri.spotify_match_id
        SET sri.spotify_title = COALESCE(NULLIF(sm.spotify_title, ''), s.title),
            sri.spotify_artist = COALESCE(NULLIF(sm.spotify_artist, ''), s.artist)
        WHERE sri.spotify_title IS NULL OR sri.spotify_artist IS NULL
        SQL,
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS login_attempts (
            client_key CHAR(64) PRIMARY KEY,
            failure_count SMALLINT UNSIGNED NOT NULL,
            window_started_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            KEY ix_login_attempts_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
];