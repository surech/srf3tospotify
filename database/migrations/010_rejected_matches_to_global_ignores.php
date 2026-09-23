<?php

declare(strict_types=1);

return [
    <<<'SQL'
        INSERT INTO song_ignore_rules (song_id, playlist_id, reason)
        SELECT sm.song_id, NULL, 'Manuell abgelehnt (Migration)'
        FROM spotify_matches sm
        WHERE sm.match_source = 'manual'
            AND sm.status = 'rejected'
            AND NOT EXISTS (
                SELECT 1
                FROM song_ignore_rules existing_rule
                WHERE existing_rule.song_id = sm.song_id
                    AND existing_rule.playlist_id IS NULL
                    AND existing_rule.reactivated_at IS NULL
            )
        SQL,
    <<<'SQL'
        UPDATE spotify_matches
        SET spotify_track_id = NULL,
            spotify_uri = NULL,
            spotify_title = NULL,
            spotify_artist = NULL,
            duration_ms = NULL,
            match_source = 'manual',
            confidence = NULL,
            status = 'review',
            checked_at = CURRENT_TIMESTAMP(6)
        WHERE match_source = 'manual' AND status = 'rejected'
        SQL,
];