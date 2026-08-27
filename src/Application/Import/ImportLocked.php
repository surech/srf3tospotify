<?php

declare(strict_types=1);

namespace App\Application\Import;

use RuntimeException;

final class ImportLocked extends RuntimeException {}
