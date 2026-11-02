<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Value object representing an HTTP response in the E2E test client.
 */
class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $statusCode,
        private string $body,
        private array $headers = []
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeaderLine(string $name): string
    {
        $normalized = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $normalized) {
                return implode(', ', $values);
            }
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }
}
