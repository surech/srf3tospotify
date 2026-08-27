<?php

declare(strict_types=1);

namespace App\Web;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    /** @param array<string, mixed> $payload
     *  @param array<string, string> $headers
     */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
        );
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function send(): never
    {
        http_response_code($this->status);
        $headers = array_merge([
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ], $this->headers);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
        exit;
    }
}
