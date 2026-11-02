<?php

declare(strict_types=1);

namespace WinCart\Http;

use WinCart\Tests\E2E\Support\HttpResponse;

final class SecurityLockfileMiddleware
{
    public function __construct(private string $lockfilePath = '')
    {
        if ($this->lockfilePath === '') {
            $this->lockfilePath = dirname(__DIR__, 2) . '/storage/installed.lock';
        }
    }

    public function handle(string $path): ?HttpResponse
    {
        $hasLockfile = file_exists($this->lockfilePath);

        if (!$hasLockfile) {
            if (!str_starts_with($path, '/install')) {
                return new HttpResponse(302, '', [
                    'Location' => ['/install'],
                    'Content-Type' => ['text/html; charset=UTF-8'],
                ]);
            }
        } else {
            if (str_starts_with($path, '/install')) {
                return new HttpResponse(403, 'Installation locked. Store is already configured.', [
                    'Content-Type' => ['text/plain; charset=UTF-8'],
                ]);
            }
        }

        return null;
    }
}
