<?php

declare(strict_types=1);

namespace WinCart\Mcp\Transports;

final class SseSessionManager
{
    private string $sessionDir;
    private int $defaultTtl = 3600;

    public function __construct(string $storagePath)
    {
        $this->sessionDir = rtrim($storagePath, '/\\') . '/cache/mcp_sessions';
        if (!is_dir($this->sessionDir)) {
            @mkdir($this->sessionDir, 0775, true);
        }
    }

    /**
     * @param list<string> $scopes
     */
    public function createSession(?string $token = null, array $scopes = []): string
    {
        $this->garbageCollect();

        $sessionId = $this->generateUuidV4();
        $now = time();

        $sessionData = [
            'sessionId' => $sessionId,
            'token' => $token,
            'scopes' => $scopes,
            'createdAt' => $now,
            'lastActivityAt' => $now,
            'ttl' => $this->defaultTtl,
        ];

        $filePath = $this->getSessionFilePath($sessionId);
        file_put_contents($filePath, json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $sessionId;
    }

    public function hasSession(string $sessionId): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $sessionId)) {
            return false;
        }

        $filePath = $this->getSessionFilePath($sessionId);
        if (!file_exists($filePath)) {
            return false;
        }

        $session = $this->getSession($sessionId);
        if ($session === null) {
            return false;
        }

        $lastActivity = (int)($session['lastActivityAt'] ?? 0);
        $ttl = (int)($session['ttl'] ?? $this->defaultTtl);

        if (time() - $lastActivity > $ttl) {
            $this->destroySession($sessionId);
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $sessionId)) {
            return null;
        }

        $filePath = $this->getSessionFilePath($sessionId);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public function touchSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId);
        if ($session !== null) {
            $session['lastActivityAt'] = time();
            $filePath = $this->getSessionFilePath($sessionId);
            file_put_contents($filePath, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function destroySession(string $sessionId): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $sessionId)) {
            $filePath = $this->getSessionFilePath($sessionId);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    public function garbageCollect(): void
    {
        if (!is_dir($this->sessionDir)) {
            return;
        }

        $now = time();
        $files = glob($this->sessionDir . '/*.json');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && ($now - $mtime) > ($this->defaultTtl * 2)) {
                @unlink($file);
            }
        }
    }

    private function getSessionFilePath(string $sessionId): string
    {
        return $this->sessionDir . '/' . $sessionId . '.json';
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
