<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE sync_runs
            ADD COLUMN IF NOT EXISTS requested_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER unresolved_count,
            ADD COLUMN IF NOT EXISTS track_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER requested_count,
            ADD COLUMN IF NOT EXISTS ignored_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER track_count,
            ADD COLUMN IF NOT EXISTS duplicate_track_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER ignored_count
        SQL,
    <<<'SQL'
        UPDATE sync_runs sr
        LEFT JOIN (
            SELECT sync_run_id, COUNT(*) AS track_count
            FROM sync_run_items
            GROUP BY sync_run_id
        ) items ON items.sync_run_id = sr.id
        SET sr.track_count = COALESCE(items.track_count, 0)
        WHERE sr.status = 'succeeded' AND sr.track_count = 0
        SQL,
];