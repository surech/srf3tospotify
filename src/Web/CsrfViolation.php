<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final class CsrfViolation extends RuntimeException {}
