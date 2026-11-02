<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

use PDO;

/**
 * Manages isolated, ephemeral test execution environments for opaque-box testing.
 * Automatically provisions and tears down SQLite databases and lockfiles.
 */
class TestEnvironment
{
    private string $baseDir;
    private string $id;
    private string $dbPath;
    private string $lockfilePath;
    private ?PDO $pdo = null;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 3);
        $this->id = bin2hex(random_bytes(8));
        $this->dbPath = $this->baseDir . '/storage/test_e2e_' . $this->id . '.sqlite';
        $this->lockfilePath = $this->baseDir . '/storage/test_installed_' . $this->id . '.lock';
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDatabasePath(): string
    {
        return $this->dbPath;
    }

    public function getLockfilePath(): string
    {
        return $this->lockfilePath;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $storageDir = dirname($this->dbPath);
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0775, true);
            }
            $this->pdo = new PDO('sqlite:' . $this->dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
        }

        return $this->pdo;
    }

    public function createLockfile(string $content = ''): void
    {
        $dir = dirname($this->lockfilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $data = $content !== '' ? $content : json_encode([
            'installed_at' => date('c'),
            'version' => '1.0.0',
            'environment' => 'testing',
        ], JSON_PRETTY_PRINT);
        file_put_contents($this->lockfilePath, (string)$data);
    }

    public function removeLockfile(): void
    {
        if (file_exists($this->lockfilePath)) {
            unlink($this->lockfilePath);
        }
    }

    public function hasLockfile(): bool
    {
        return file_exists($this->lockfilePath);
    }

    /**
     * @return array<string, string>
     */
    public function getEnvVariables(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->dbPath,
            'INSTALL_LOCK_PATH' => $this->lockfilePath,
        ];
    }

    public function cleanUp(): void
    {
        $this->pdo = null;

        // Cleanup SQLite files (main, wal, shm)
        $filesToClean = [
            $this->dbPath,
            $this->dbPath . '-wal',
            $this->dbPath . '-shm',
            $this->lockfilePath,
        ];

        foreach ($filesToClean as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
}
