<?php

declare(strict_types=1);

namespace App\Infrastructure\Srf;

use App\Domain\RadioPlay;
use App\Infrastructure\Http\HttpClient;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final readonly class SrfClient implements SrfSource
{
    public function __construct(
        private HttpClient $httpClient,
        private string $baseUrl,
        private string $channelId,
        private int $pageSize = 500,
    ) {
        if ($pageSize < 1) {
            throw new SrfException('SRF page size must be positive.');
        }
    }

    public function fetch(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): array
    {
        if ($fromLocal >= $toLocal) {
            throw new SrfException('SRF interval start must precede end.');
        }

        $plays = $this->fetchWindow($fromLocal, $toLocal);
        $unique = [];
        foreach ($plays as $play) {
            $unique[bin2hex($play->eventHash($this->channelId))] = $play;
        }

        return array_values($unique);
    }

    /** @return list<RadioPlay> */
    private function fetchWindow(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): array
    {
        $query = http_build_query([
            'from' => $fromLocal->format(DATE_ATOM),
            'to' => $toLocal->format(DATE_ATOM),
            'pageSize' => $this->pageSize,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->channelId) . '?' . $query;
        $response = $this->httpClient->request('GET', $url, ['Accept' => 'application/json']);

        if ($response->status !== 200) {
            throw new SrfException(\sprintf('SRF request failed with HTTP %d.', $response->status));
        }
        $contentType = $response->header('content-type');
        if ($contentType !== null && !str_starts_with(strtolower($contentType), 'application/json')) {
            throw new SrfException('SRF returned unsupported content type.');
        }

        $plays = $this->parse($response->body);
        if (\count($plays) < $this->pageSize) {
            return $plays;
        }

        $seconds = $toLocal->getTimestamp() - $fromLocal->getTimestamp();
        if ($seconds <= 3600) {
            throw new SrfException('SRF result may be truncated within a one-hour interval.');
        }
        $midpoint = $fromLocal->setTimestamp($fromLocal->getTimestamp() + intdiv($seconds, 2));

        return array_merge(
            $this->fetchWindow($fromLocal, $midpoint),
            $this->fetchWindow($midpoint, $toLocal),
        );
    }

    /** @return list<RadioPlay> */
    private function parse(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SrfException('SRF returned malformed JSON.', previous: $exception);
        }

        if (!\is_array($payload) || !isset($payload['songList']) || !\is_array($payload['songList'])) {
            throw new SrfException('SRF response has no songList array.');
        }

        $plays = [];
        foreach ($payload['songList'] as $index => $item) {
            if (!\is_array($item)) {
                throw new SrfException(\sprintf('SRF songList[%d] must be an object.', $index));
            }

            $date = $item['date'] ?? null;
            $duration = $item['duration'] ?? null;
            $title = $item['title'] ?? null;
            $artist = $item['artist'] ?? null;
            $isPlayingNow = $item['isPlayingNow'] ?? null;
            if (
                !\is_string($date)
                || !\is_int($duration)
                || !\is_string($title)
                || !\is_array($artist)
                || !\is_string($artist['name'] ?? null)
                || !\is_bool($isPlayingNow)
            ) {
                throw new SrfException(\sprintf('SRF songList[%d] has invalid fields.', $index));
            }

            try {
                $sourceDate = new DateTimeImmutable($date);
            } catch (Throwable $exception) {
                throw new SrfException(\sprintf('SRF songList[%d] has invalid date.', $index), previous: $exception);
            }

            $plays[] = new RadioPlay(
                $sourceDate->setTimezone(new DateTimeZone('UTC')),
                intdiv($sourceDate->getOffset(), 60),
                $duration,
                trim($artist['name']),
                trim($title),
                $isPlayingNow,
            );
        }

        return $plays;
    }
}
