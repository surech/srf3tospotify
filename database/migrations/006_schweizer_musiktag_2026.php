<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE playlists
            ADD COLUMN fixed_from_utc DATETIME(6) NULL AFTER local_end_minute,
            ADD COLUMN fixed_to_utc DATETIME(6) NULL AFTER fixed_from_utc
        SQL,
    <<<'SQL'
        INSERT INTO playlists (
            name, description, ranking_days, max_tracks, weekdays_only,
            local_start_minute, local_end_minute, fixed_from_utc, fixed_to_utc, is_public
        )
        SELECT
            'SRF 3 - Schweizer Musiktag 2026',
            'Alle auf SRF 3 gespielten Songs vom Schweizer Musiktag 2026 am 17.09.2026, 05:00 bis 23:59 Uhr (Schweizer Zeit).',
            1, 500, 0, NULL, NULL, '2026-09-17 03:00:00', '2026-09-17 22:00:00', 1
        WHERE NOT EXISTS (
            SELECT 1 FROM playlists WHERE name = 'SRF 3 - Schweizer Musiktag 2026'
        )
        SQL,
];