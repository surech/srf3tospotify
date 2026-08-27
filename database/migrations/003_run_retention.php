<?php

declare(strict_types=1);

return [
    'ALTER TABLE plays DROP FOREIGN KEY fk_plays_import',
    'ALTER TABLE plays MODIFY import_run_id BIGINT UNSIGNED NULL',
    'ALTER TABLE plays ADD CONSTRAINT fk_plays_import FOREIGN KEY (import_run_id) REFERENCES import_runs (id) ON DELETE SET NULL',
];