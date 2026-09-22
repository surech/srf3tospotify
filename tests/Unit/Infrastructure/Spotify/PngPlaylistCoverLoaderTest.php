<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Spotify;

use App\Infrastructure\Spotify\PngPlaylistCoverLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PngPlaylistCoverLoader::class)]
final class PngPlaylistCoverLoaderTest extends TestCase
{
    #[DataProvider('coverFiles')]
    public function testConvertsPlaylistCoverToSpotifyCompatibleJpeg(string $filename): void
    {
        $jpeg = (new PngPlaylistCoverLoader())->load(
            \dirname(__DIR__, 4) . '/resources/playlist-covers/' . $filename,
        );

        self::assertStringStartsWith("\xFF\xD8", $jpeg);
        self::assertStringEndsWith("\xFF\xD9", $jpeg);
        self::assertLessThanOrEqual(256 * 1024, \strlen(base64_encode($jpeg)));
        $imageInfo = getimagesizefromstring($jpeg);
        self::assertIsArray($imageInfo);
        self::assertSame([920, 920], \array_slice($imageInfo, 0, 2));
    }

    /** @return iterable<string, array{string}> */
    public static function coverFiles(): iterable
    {
        yield 'Top 50' => ['top50.png'];
        yield 'Der Morgen' => ['der-morgen.png'];
    }
}
