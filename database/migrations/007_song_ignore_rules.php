<?php

declare(strict_types=1);

return [
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS song_ignore_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            song_id BIGINT UNSIGNED NOT NULL,
            playlist_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NULL,
            ignored_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            reactivated_at DATETIME(6) NULL,
            active_song_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN reactivated_at IS NULL THEN song_id ELSE NULL END) STORED,
            scope_playlist_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (IFNULL(playlist_id, 0)) STORED,
            UNIQUE KEY uq_song_ignore_active_scope (active_song_id, scope_playlist_id),
            KEY ix_song_ignore_active_playlist (reactivated_at, playlist_id),
            KEY ix_song_ignore_song (song_id, ignored_at),
            CONSTRAINT fk_song_ignore_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE,
            CONSTRAINT fk_song_ignore_playlist FOREIGN KEY (playlist_id) REFERENCES playlists (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
];