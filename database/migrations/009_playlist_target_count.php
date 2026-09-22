<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE playlists
            ADD COLUMN IF NOT EXISTS target_tracks SMALLINT UNSIGNED NULL AFTER max_tracks
        SQL,
    <<<'SQL'
        UPDATE playlists
        SET target_tracks = 50
        WHERE name IN ('SRF 3 - Top 50', 'SRF 3 - Der Morgen')
            AND target_tracks IS NULL
        SQL,
    <<<'SQL'
        UPDATE sync_runs sr
        INNER JOIN playlists p ON p.id = sr.playlist_id
        SET sr.requested_count = COALESCE(p.target_tracks, sr.track_count)
        WHERE sr.status = 'succeeded' AND sr.requested_count = 0
        SQL,
];