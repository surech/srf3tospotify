<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE playlists
            MODIFY COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1
        SQL,
    <<<'SQL'
        UPDATE playlists SET is_public = 1
        SQL,
];