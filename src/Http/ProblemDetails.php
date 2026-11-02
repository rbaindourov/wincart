<?php

declare(strict_types=1);

namespace WinCart\Http;

use WinCart\Tests\E2E\Support\HttpResponse;

/**
 * RFC 7807 Problem Details response formatter.
 */
final class ProblemDetails
{
    /**
     * @param array<string, mixed> $extensions
     */
    public static function create(
        int $status,
        string $title,
        string $detail,
        array $extensions = []
    ): HttpResponse {
        $body = array_merge([
            'type' => 'https://tools.ietf.org/html/rfc7807',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extensions);

        return new HttpResponse(
            $status,
            (string)json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            [
                'Content-Type' => ['application/problem+json; charset=utf-8'],
            ]
        );
    }
}
