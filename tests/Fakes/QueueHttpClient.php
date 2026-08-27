<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Http\HttpResponse;
use RuntimeException;

final class QueueHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses) {}

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 20,
    ): HttpResponse {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) {
            throw new RuntimeException('No queued HTTP response.');
        }

        return $response;
    }
}
