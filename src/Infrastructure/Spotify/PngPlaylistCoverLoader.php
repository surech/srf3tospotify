<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

final class PngPlaylistCoverLoader
{
    private const JPEG_QUALITY = 85;

    public function load(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SpotifyException('Playlist cover PNG is missing or unreadable.');
        }

        $source = @imagecreatefrompng($path);
        if ($source === false) {
            throw new SpotifyException('Playlist cover is not a valid PNG image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new SpotifyException('Playlist cover image could not be created.');
        }

        try {
            $background = imagecolorallocate($image, 255, 255, 255);
            if ($background === false
                || !imagefill($image, 0, 0, $background)
                || !imagecopy($image, $source, 0, 0, 0, 0, $width, $height)
            ) {
                throw new SpotifyException('Playlist cover PNG could not be converted.');
            }

            ob_start();
            $encoded = imagejpeg($image, null, self::JPEG_QUALITY);
            $jpeg = ob_get_clean();
            if (!$encoded || !\is_string($jpeg) || $jpeg === '') {
                throw new SpotifyException('Playlist cover JPEG could not be encoded.');
            }

            return $jpeg;
        } finally {
            imagedestroy($image);
            imagedestroy($source);
        }
    }
}
