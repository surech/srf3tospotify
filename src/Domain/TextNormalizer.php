<?php

declare(strict_types=1);

namespace App\Domain;

use Normalizer;

final class TextNormalizer
{
    public static function normalize(string $value): string
    {
        $unicode = Normalizer::normalize($value, Normalizer::FORM_C);
        $trimmed = trim($unicode === false ? $value : $unicode);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return mb_strtolower($collapsed ?? $trimmed, 'UTF-8');
    }
}
