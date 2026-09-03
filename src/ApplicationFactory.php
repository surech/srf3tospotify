<?php

declare(strict_types=1);

namespace App;

use App\Application\Import\ImportService;
use App\Application\Maintenance\CleanupService;
use App\Application\Ranking\RankingService;
use App\Application\Spotify\MatchingEngine;
use App\Application\Spotify\MatchingService;
use App\Application\Spotify\PlaylistSyncService;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Database\MaintenanceRepository;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\OAuthTokenRepository;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Database\RankingRepository;
use App\Infrastructure\Database\SpotifyMatchRepository;
use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Security\TokenCipher;
use App\Infrastructure\Spotify\PngPlaylistCoverLoader;
use App\Infrastructure\Spotify\SpotifyClient;
use App\Infrastructure\Spotify\SpotifyOAuth;
use App\Infrastructure\Srf\SrfClient;
use App\Support\Config;
use App\Support\JsonLogger;
use App\Support\JsonLogPruner;
use DateTimeZone;
use PDO;

final class ApplicationFactory
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $projectRoot,
    ) {}

    public function connection(): PDO
    {
        return $this->connection ??= (new ConnectionFactory($this->config))->create();
    }

    public function importService(): ImportService
    {
        $connection = $this->connection();
        $channelId = $this->config->string('SRF_CHANNEL_ID');

        return new ImportService(
            new SrfClient(
                new CurlHttpClient(),
                $this->config->string('SRF_BASE_URL'),
                $channelId,
                $this->config->int('SRF_PAGE_SIZE', 500),
            ),
            new ImportRepository($connection),
            new AdvisoryLock($connection),
            new JsonLogger($this->projectRoot . '/var/log/application.log'),
            $channelId,
            new DateTimeZone('Europe/Zurich'),
        );
    }

    public function rankingService(): RankingService
    {
        return new RankingService(
            new RankingRepository($this->connection()),
            new DateTimeZone('Europe/Zurich'),
        );
    }

    public function spotifyOAuth(): SpotifyOAuth
    {
        return new SpotifyOAuth(
            new CurlHttpClient(),
            new OAuthTokenRepository(
                $this->connection(),
                new TokenCipher($this->config->required('APP_KEY')),
            ),
            $this->config->string('SPOTIFY_CLIENT_ID'),
            $this->config->string('SPOTIFY_CLIENT_SECRET'),
        );
    }

    public function spotifyClient(): SpotifyClient
    {
        return new SpotifyClient(new CurlHttpClient(), $this->spotifyOAuth());
    }

    public function matchingService(): MatchingService
    {
        return new MatchingService(
            $this->spotifyClient(),
            new MatchingEngine(),
            new SpotifyMatchRepository($this->connection()),
        );
    }

    public function playlistSyncService(): PlaylistSyncService
    {
        $connection = $this->connection();
        $spotify = $this->spotifyClient();
        $coverLoader = new PngPlaylistCoverLoader();
        $coverDirectory = $this->projectRoot . '/resources/playlist-covers';

        return new PlaylistSyncService(
            $this->rankingService(),
            new MatchingService(
                $spotify,
                new MatchingEngine(),
                new SpotifyMatchRepository($connection),
            ),
            new SpotifyMatchRepository($connection),
            new PlaylistRepository($connection),
            $spotify,
            new AdvisoryLock($connection),
            new JsonLogger($this->projectRoot . '/var/log/application.log'),
            new DateTimeZone('Europe/Zurich'),
            [
                'SRF 3 - Top 50' => $coverLoader->load($coverDirectory . '/top50.png'),
                'SRF 3 - Der Morgen' => $coverLoader->load($coverDirectory . '/der-morgen.png'),
            ],
        );
    }

    public function cleanupService(): CleanupService
    {
        $logPath = $this->projectRoot . '/var/log/application.log';

        return new CleanupService(
            new MaintenanceRepository($this->connection()),
            new JsonLogPruner($logPath),
            new JsonLogger($logPath),
        );
    }

    /** @return array{applied: list<string>, skipped: list<string>} */
    public function migrate(): array
    {
        return (new Migrator(
            $this->connection(),
            $this->projectRoot . '/database/migrations',
        ))->migrate();
    }
}
