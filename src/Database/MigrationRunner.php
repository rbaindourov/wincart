<?php

declare(strict_types=1);

namespace WinCart\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Migration runner with self-bootstrapping migrations table,
 * driver block parsing (-- @driver: sqlite / -- @driver: mysql),
 * batch tracking, and idempotent execution.
 */
final class MigrationRunner
{
    private string $driver;

    public function __construct(
        private PDO $pdo,
        private string $migrationsPath = ''
    ) {
        $this->driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($this->migrationsPath === '') {
            $this->migrationsPath = dirname(__DIR__, 2) . '/database/migrations';
        }
        $this->bootstrap();
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    public function bootstrap(): void
    {
        if ($this->driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        }

        $this->pdo->exec($sql);
    }

    /**
     * Apply all pending migrations.
     *
     * @return array<string> List of applied migration filenames
     */
    public function migrateUp(): array
    {
        $this->bootstrap();
        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();

        $pending = array_diff($files, $applied);
        if (empty($pending)) {
            return [];
        }

        sort($pending);
        $nextBatch = $this->getNextBatchNumber();
        $newlyApplied = [];

        foreach ($pending as $filename) {
            $filePath = $this->migrationsPath . '/' . $filename;
            $sqlContent = (string)file_get_contents($filePath);
            $statements = $this->parseDriverStatements($sqlContent);

            $this->pdo->beginTransaction();
            try {
                foreach ($statements as $stmt) {
                    $trimmed = trim($stmt);
                    if ($trimmed !== '') {
                        $this->pdo->exec($trimmed);
                    }
                }

                $logStmt = $this->pdo->prepare(
                    'INSERT INTO migrations (migration, batch, applied_at) VALUES (:migration, :batch, :applied_at)'
                );
                $logStmt->execute([
                    ':migration' => $filename,
                    ':batch' => $nextBatch,
                    ':applied_at' => date('Y-m-d H:i:s'),
                ]);

                $this->pdo->commit();
                $newlyApplied[] = $filename;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration failed in '{$filename}': " . $e->getMessage(), 0, $e);
            }
        }

        return $newlyApplied;
    }

    /**
     * Rollback the most recent batch of migrations.
     *
     * @return array<string> List of rolled back migration names
     */
    public function rollbackLastBatch(): array
    {
        $this->bootstrap();
        $stmt = $this->pdo->query('SELECT MAX(batch) FROM migrations');
        $lastBatch = (int)$stmt->fetchColumn();

        if ($lastBatch <= 0) {
            return [];
        }

        $query = $this->pdo->prepare('SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC');
        $query->execute([':batch' => $lastBatch]);
        $migrations = $query->fetchAll(PDO::FETCH_COLUMN);

        $deleteStmt = $this->pdo->prepare('DELETE FROM migrations WHERE batch = :batch');
        $deleteStmt->execute([':batch' => $lastBatch]);

        return is_array($migrations) ? $migrations : [];
    }

    /**
     * Get migration status overview.
     *
     * @return array<array{name: string, applied: bool, batch: ?int, applied_at: ?string}>
     */
    public function getStatus(): array
    {
        $this->bootstrap();
        $files = $this->getMigrationFiles();
        sort($files);

        $stmt = $this->pdo->query('SELECT migration, batch, applied_at FROM migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $appliedMap = [];
        foreach ($rows as $row) {
            $appliedMap[$row['migration']] = $row;
        }

        $status = [];
        foreach ($files as $file) {
            $isApplied = isset($appliedMap[$file]);
            $status[] = [
                'name' => $file,
                'applied' => $isApplied,
                'batch' => $isApplied ? (int)$appliedMap[$file]['batch'] : null,
                'applied_at' => $isApplied ? (string)$appliedMap[$file]['applied_at'] : null,
            ];
        }

        return $status;
    }

    /**
     * Parse SQL file content and extract statements for the active database driver.
     *
     * @return array<string>
     */
    public function parseDriverStatements(string $sql): array
    {
        $lines = explode("\n", $sql);
        $currentSection = 'common';
        $driverSql = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^--\s*@driver:\s*([a-zA-Z0-9_-]+)/i', $trimmed, $matches)) {
                $currentSection = strtolower($matches[1]);
                continue;
            }

            if ($currentSection === 'common' || $currentSection === $this->driver) {
                // Ignore comment lines that start with --
                if (!str_starts_with($trimmed, '--')) {
                    $driverSql .= $line . "\n";
                }
            }
        }

        return $this->splitStatements($driverSql);
    }

    /**
     * Split multi-statement SQL into individual statements safely.
     *
     * @return array<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
            } else {
                if ($char === "'" || $char === '"' || $char === '`') {
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * @return array<string>
     */
    private function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string>
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, fn(string $f) => str_ends_with($f, '.sql')));
    }

    private function getNextBatchNumber(): int
    {
        $stmt = $this->pdo->query('SELECT MAX(batch) FROM migrations');
        $max = (int)$stmt->fetchColumn();
        return $max + 1;
    }
}
