<?php

declare(strict_types=1);

namespace WinCart\Database;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Production PDO connection factory supporting SQLite 3 and MySQL 8.0+.
 * Enforces strict typing, exceptions on error, associative fetches, WAL mode,
 * foreign key validation, UTF-8 charsets, and atomic transaction wrappers.
 */
final class ConnectionFactory
{
    /**
     * Create a configured PDO connection instance from an array of options.
     *
     * @param array<string, mixed> $config Connection configuration:
     *   - 'driver': 'sqlite' | 'mysql'
     *   - 'database' | 'path': file path or ':memory:' for SQLite, or database name for MySQL
     *   - 'host': MySQL host (default: '127.0.0.1')
     *   - 'port': MySQL port (default: 3306)
     *   - 'username': MySQL user (default: 'root')
     *   - 'password': MySQL password (default: '')
     *   - 'charset': MySQL charset (default: 'utf8mb4')
     */
    public static function create(array $config): PDO
    {
        $driver = strtolower((string)($config['driver'] ?? $config['db-driver'] ?? 'sqlite'));

        return match ($driver) {
            'sqlite' => self::createSqliteConnection($config),
            'mysql' => self::createMysqlConnection($config),
            default => throw new InvalidArgumentException("Unsupported database driver: '{$driver}'"),
        };
    }

    /**
     * Create connection using environment variables (with fallback to default SQLite).
     */
    public static function createFromEnv(): PDO
    {
        $driver = (string)(getenv('DB_CONNECTION') ?: 'sqlite');
        $database = (string)(getenv('DB_DATABASE') ?: dirname(__DIR__, 2) . '/storage/database.sqlite');

        return self::create([
            'driver' => $driver,
            'database' => $database,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ]);
    }

    /**
     * Execute a callback inside an atomic database transaction.
     * Automatically commits on success and rolls back on any Throwable.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     * @throws Throwable
     */
    public static function transactional(PDO $pdo, callable $callback): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createSqliteConnection(array $config): PDO
    {
        $path = (string)($config['database'] ?? $config['db-path'] ?? $config['path'] ?? ':memory:');

        if ($path !== ':memory:' && !str_starts_with($path, 'sqlite:')) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $dsn = str_starts_with($path, 'sqlite:') ? $path : "sqlite:{$path}";

        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        if ($path !== ':memory:') {
            try {
                $pdo->exec('PRAGMA journal_mode = WAL;');
            } catch (Throwable) {
                // Ignore if journal_mode cannot be set (e.g. read-only or in-memory)
            }
        }

        return $pdo;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createMysqlConnection(array $config): PDO
    {
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 3306);
        $dbname = (string)($config['database'] ?? $config['dbname'] ?? 'wincart');
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $user = (string)($config['username'] ?? $config['user'] ?? 'root');
        $pass = (string)($config['password'] ?? $config['pass'] ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        ]);
    }
}
