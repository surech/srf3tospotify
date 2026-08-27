<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Spotify\MatchDecision;
use App\Infrastructure\Spotify\SpotifyTrack;
use PDO;

final readonly class SpotifyMatchRepository
{
    public function __construct(private PDO $connection) {}

    public function find(int $songId): ?StoredSpotifyMatch
    {
        $query = $this->connection->prepare(
            'SELECT id, song_id, spotify_track_id, spotify_uri, match_source, status, confidence '
            . 'FROM spotify_matches WHERE song_id = :song_id',
        );
        $query->execute(['song_id' => $songId]);
        $row = $query->fetch();

        return $row === false ? null : $this->map($row);
    }

    /** @param list<int> $songIds
     *  @return array<int, StoredSpotifyMatch>
     */
    public function findAccepted(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, \count($songIds), '?'));
        $query = $this->connection->prepare(
            'SELECT id, song_id, spotify_track_id, spotify_uri, match_source, status, confidence '
            . "FROM spotify_matches WHERE status = 'accepted' AND song_id IN ($placeholders)",
        );
        $query->execute($songIds);

        $matches = [];
        while (($row = $query->fetch()) !== false) {
            $match = $this->map($row);
            $matches[$match->songId] = $match;
        }

        return $matches;
    }

    public function saveAutomatic(int $songId, MatchDecision $decision): StoredSpotifyMatch
    {
        $existing = $this->find($songId);
        if ($existing?->source === 'manual') {
            return $existing;
        }

        $this->upsert($songId, $decision->track, 'automatic', $decision->status, $decision->confidence);

        return $this->find($songId) ?? throw new \RuntimeException('Unable to reload Spotify match.');
    }

    public function saveManualTrack(int $songId, SpotifyTrack $track): StoredSpotifyMatch
    {
        $this->upsert($songId, $track, 'manual', 'accepted', 1.0);

        return $this->find($songId) ?? throw new \RuntimeException('Unable to reload manual Spotify match.');
    }

    public function saveManualRejection(int $songId): StoredSpotifyMatch
    {
        $this->upsert($songId, null, 'manual', 'rejected', null);

        return $this->find($songId) ?? throw new \RuntimeException('Unable to reload rejected Spotify match.');
    }

    private function upsert(
        int $songId,
        ?SpotifyTrack $track,
        string $source,
        string $status,
        ?float $confidence,
    ): void {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO spotify_matches (
                    song_id, spotify_track_id, spotify_uri, spotify_title, spotify_artist,
                    duration_ms, match_source, confidence, status, checked_at
                ) VALUES (
                    :song_id, :track_id, :uri, :title, :artist,
                    :duration_ms, :match_source, :confidence, :status, CURRENT_TIMESTAMP(6)
                ) ON DUPLICATE KEY UPDATE
                    spotify_track_id = VALUES(spotify_track_id),
                    spotify_uri = VALUES(spotify_uri),
                    spotify_title = VALUES(spotify_title),
                    spotify_artist = VALUES(spotify_artist),
                    duration_ms = VALUES(duration_ms),
                    match_source = VALUES(match_source),
                    confidence = VALUES(confidence),
                    status = VALUES(status),
                    checked_at = VALUES(checked_at)
                SQL,
        );
        $query->execute([
            'song_id' => $songId,
            'track_id' => $track?->id,
            'uri' => $track?->uri,
            'title' => $track?->title,
            'artist' => $track?->artistLabel(),
            'duration_ms' => $track?->durationMs,
            'match_source' => $source,
            'confidence' => $confidence,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): StoredSpotifyMatch
    {
        return new StoredSpotifyMatch(
            (int) $row['id'],
            (int) $row['song_id'],
            $row['spotify_track_id'] === null ? null : (string) $row['spotify_track_id'],
            $row['spotify_uri'] === null ? null : (string) $row['spotify_uri'],
            (string) $row['match_source'],
            (string) $row['status'],
            $row['confidence'] === null ? null : (float) $row['confidence'],
        );
    }
}
