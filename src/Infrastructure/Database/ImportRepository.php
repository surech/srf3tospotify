<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\RadioPlay;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final readonly class ImportRepository
{
    public function __construct(private PDO $connection) {}

    public function channelDatabaseId(string $sourceChannelId): int
    {
        $query = $this->connection->prepare(
            'SELECT id FROM radio_channels WHERE source_channel_id = :source_channel_id AND enabled = 1',
        );
        $query->execute(['source_channel_id' => $sourceChannelId]);
        $id = $query->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Configured SRF channel is not enabled in database.');
        }

        return (int) $id;
    }

    public function startRun(
        string $correlationId,
        string $triggerType,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
    ): int {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO import_runs (
                    correlation_id, trigger_type, range_from_utc, range_to_utc, status
                ) VALUES (
                    :correlation_id, :trigger_type, :range_from_utc, :range_to_utc, 'running'
                )
                SQL,
        );
        $query->execute([
            'correlation_id' => $correlationId,
            'trigger_type' => $triggerType,
            'range_from_utc' => self::formatDate($fromUtc),
            'range_to_utc' => self::formatDate($toUtcExclusive),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @param list<RadioPlay> $plays
     *  @return array{inserted: int, duplicates: int}
     */
    public function persistPlays(
        int $runId,
        int $channelDatabaseId,
        string $sourceChannelId,
        array $plays,
    ): array {
        $songQuery = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO songs (
                    identity_hash, artist, title, normalized_artist, normalized_title
                ) VALUES (
                    :identity_hash, :artist, :title, :normalized_artist, :normalized_title
                ) ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    artist = VALUES(artist),
                    title = VALUES(title),
                    normalized_artist = VALUES(normalized_artist),
                    normalized_title = VALUES(normalized_title)
                SQL,
        );
        $playQuery = $this->connection->prepare(
            <<<'SQL'
                INSERT IGNORE INTO plays (
                    event_hash, radio_channel_id, song_id, import_run_id,
                    played_at_utc, source_offset_minutes, duration_ms, was_playing_now
                ) VALUES (
                    :event_hash, :radio_channel_id, :song_id, :import_run_id,
                    :played_at_utc, :source_offset_minutes, :duration_ms, :was_playing_now
                )
                SQL,
        );

        $inserted = 0;
        $this->connection->beginTransaction();
        try {
            foreach ($plays as $play) {
                $songQuery->execute([
                    'identity_hash' => $play->identityHash(),
                    'artist' => $play->artist,
                    'title' => $play->title,
                    'normalized_artist' => $play->normalizedArtist,
                    'normalized_title' => $play->normalizedTitle,
                ]);
                $songId = (int) $this->connection->lastInsertId();
                if ($songId === 0) {
                    throw new RuntimeException('Unable to resolve persisted song.');
                }

                $playQuery->execute([
                    'event_hash' => $play->eventHash($sourceChannelId),
                    'radio_channel_id' => $channelDatabaseId,
                    'song_id' => $songId,
                    'import_run_id' => $runId,
                    'played_at_utc' => self::formatDate($play->playedAtUtc),
                    'source_offset_minutes' => $play->sourceOffsetMinutes,
                    'duration_ms' => $play->durationMs,
                    'was_playing_now' => $play->wasPlayingNow ? 1 : 0,
                ]);
                $inserted += $playQuery->rowCount();
            }
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return ['inserted' => $inserted, 'duplicates' => \count($plays) - $inserted];
    }

    public function finishRun(int $runId, int $received, int $inserted, int $duplicates): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                UPDATE import_runs
                SET status = 'succeeded', received_count = :received, inserted_count = :inserted,
                    duplicate_count = :duplicates, finished_at = CURRENT_TIMESTAMP(6)
                WHERE id = :id
                SQL,
        );
        $query->execute([
            'received' => $received,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'id' => $runId,
        ]);
    }

    public function failRun(int $runId, string $message): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                UPDATE import_runs
                SET status = 'failed', error_summary = :error_summary, finished_at = CURRENT_TIMESTAMP(6)
                WHERE id = :id
                SQL,
        );
        $query->execute([
            'error_summary' => mb_substr($message, 0, 1000),
            'id' => $runId,
        ]);
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
