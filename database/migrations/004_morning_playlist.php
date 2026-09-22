<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE playlists
            ADD COLUMN weekdays_only TINYINT(1) NOT NULL DEFAULT 0 AFTER max_tracks,
            ADD COLUMN local_start_minute SMALLINT UNSIGNED NULL AFTER weekdays_only,
            ADD COLUMN local_end_minute SMALLINT UNSIGNED NULL AFTER local_start_minute
        SQL,
    <<<'SQL'
        INSERT INTO playlists (
            name, description, ranking_days, max_tracks, weekdays_only,
            local_start_minute, local_end_minute, is_public
        )
        SELECT
            'SRF 3 - Der Morgen',
            'Most-played SRF 3 songs from the last 30 complete days, Monday to Friday from 06:00 to 10:00 Swiss time.',
            30, 50, 1, 360, 600, 0
        WHERE NOT EXISTS (
            SELECT 1 FROM playlists WHERE name = 'SRF 3 - Der Morgen'
        )
        SQL,
];