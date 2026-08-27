<?php

declare(strict_types=1);

return [
    <<<'SQL'
        INSERT INTO playlists (name, description, ranking_days, max_tracks, is_public)
        SELECT 'SRF 3 - Top 50', 'Most-played SRF 3 songs from the last 30 complete days.', 30, 50, 0
        WHERE NOT EXISTS (SELECT 1 FROM playlists)
        SQL,
];