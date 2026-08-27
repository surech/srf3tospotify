<?php

declare(strict_types=1);

namespace App\Web;

final readonly class Request
{
    /** @param array<string, string> $query
     *  @param array<string, string> $form
     *  @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $form = [],
        public array $headers = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_') || !\is_string($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
        if (isset($_SERVER['CONTENT_TYPE']) && \is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (!isset($headers['authorization'])) {
            $redirectedAuthorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['AUTHORIZATION'] ?? null;
            if (\is_string($redirectedAuthorization)) {
                $headers['authorization'] = $redirectedAuthorization;
            }
        }

        return new self(
            $method,
            \is_string($path) && $path !== '' ? $path : '/',
            self::stringValues($_GET),
            self::stringValues($_POST),
            $headers,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @param array<array-key, mixed> $values
     *  @return array<string, string>
     */
    private static function stringValues(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
