<?php

declare(strict_types=1);

namespace WinCart\Installer;

use PDO;
use Throwable;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Database\DemoDataSeeder;

final class InstallerService
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
    }

    public function isInstalled(?string $lockPath = null): bool
    {
        $targetLock = $lockPath ?? $this->resolveLockfilePath([]);
        return file_exists($targetLock);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function install(array $config): InstallResult
    {
        // 1. Environment validation
        $env = EnvironmentChecker::check($this->baseDir);
        if (!$env['all_passed']) {
            return InstallResult::failure($env['errors'], 1);
        }

        // 2. Lockfile resolution & guard
        $lockPath = $this->resolveLockfilePath($config);
        $force = !empty($config['force']);
        if (file_exists($lockPath) && !$force) {
            return InstallResult::failure([
                "winCart is already installed. Lockfile exists at: {$lockPath}",
                "To reinstall, remove the lockfile or use --force.",
            ], 1);
        }

        // 3. Database connection setup
        $driver = strtolower((string)($config['db_driver'] ?? 'sqlite'));
        $dbConfig = ['driver' => $driver];

        if ($driver === 'sqlite') {
            $dbPath = (string)($config['db_path'] ?? ($this->baseDir . '/storage/database.sqlite'));
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0775, true);
            }
            $dbConfig['database'] = $dbPath;
        } else {
            $dbConfig['host'] = $config['db_host'] ?? '127.0.0.1';
            $dbConfig['port'] = (int)($config['db_port'] ?? 3306);
            $dbConfig['database'] = $config['db_name'] ?? 'wincart';
            $dbConfig['username'] = $config['db_user'] ?? 'root';
            $dbConfig['password'] = $config['db_pass'] ?? '';
            $dbConfig['charset'] = $config['db_charset'] ?? 'utf8mb4';
        }

        try {
            $pdo = ConnectionFactory::create($dbConfig);
        } catch (Throwable $e) {
            return InstallResult::failure(['Database connection failed: ' . $e->getMessage()], 1);
        }

        // 4. Run migrations
        try {
            $migrationsPath = $this->baseDir . '/database/migrations';
            $runner = new MigrationRunner($pdo, $migrationsPath);
            $applied = $runner->migrateUp();
        } catch (Throwable $e) {
            return InstallResult::failure(['Migration execution failed: ' . $e->getMessage()], 1);
        }

        // 5. Seed demo data if requested (run before admin provisioning so fixtures have stable IDs)
        if (!empty($config['demo_data']) || !empty($config['seed'])) {
            try {
                DemoDataSeeder::seed($pdo);
            } catch (Throwable $e) {
                return InstallResult::failure(['Demo catalog seeding failed: ' . $e->getMessage()], 1);
            }
        }

        // 6. Provision Administrator Account & PAT Token
        $adminUser = (string)($config['admin_user'] ?? 'admin');
        $adminEmail = (string)($config['admin_email'] ?? 'admin@wincart.local');
        $adminPass = (string)($config['admin_pass'] ?? 'Secret123!');
        $plainPat = 'wincart_pat_' . bin2hex(random_bytes(24));
        $patHash = hash('sha256', $plainPat);
        $passHash = password_hash($adminPass, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');
        $scopes = 'read:products,write:products,read:orders,write:orders,read:customers,write:customers,'
            . 'manage:settings,admin,read,write,products:read,products:write,orders:read,orders:write,'
            . 'cart:read,admin:read,admin:write';

        try {
            $checkStmt = $pdo->prepare('SELECT id FROM customers WHERE email = :email');
            $checkStmt->execute([':email' => $adminEmail]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId !== false) {
                $upStmt = $pdo->prepare('UPDATE customers SET 
                    password_hash = :pass,
                    role = :role,
                    pat_token_hash = :pat,
                    pat_scopes = :scopes,
                    updated_at = :now
                    WHERE id = :id');
                $upStmt->execute([
                    ':pass' => $passHash,
                    ':role' => 'admin',
                    ':pat' => $patHash,
                    ':scopes' => $scopes,
                    ':now' => $now,
                    ':id' => $existingId,
                ]);
            } else {
                $insStmt = $pdo->prepare('INSERT INTO customers (
                    email, password_hash, first_name, last_name, role,
                    pat_token_hash, pat_scopes, is_active, created_at, updated_at
                ) VALUES (
                    :email, :pass, :fname, :lname, :role,
                    :pat, :scopes, 1, :now, :now
                )');
                $insStmt->execute([
                    ':email' => $adminEmail,
                    ':pass' => $passHash,
                    ':fname' => $adminUser,
                    ':lname' => 'Administrator',
                    ':role' => 'admin',
                    ':pat' => $patHash,
                    ':scopes' => $scopes,
                    ':now' => $now,
                ]);
            }
        } catch (Throwable $e) {
            return InstallResult::failure(['Admin user provisioning failed: ' . $e->getMessage()], 1);
        }

        // 7. Initialize store settings
        $storeName = (string)($config['store_name'] ?? 'winCart Store');
        $storeCurrency = (string)($config['store_currency'] ?? 'USD');
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
                    key VARCHAR(100) PRIMARY KEY,
                    value TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
                    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
                    `value` LONGTEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            }

            $mysqlUpsert = 'INSERT INTO store_settings (`key`, `value`, updated_at)'
                . ' VALUES (:k, :v, :now) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`),'
                . ' updated_at = VALUES(updated_at)';
            $setStmt = $pdo->prepare($driver === 'sqlite'
                ? 'INSERT OR REPLACE INTO store_settings (key, value, updated_at) VALUES (:k, :v, :now)'
                : $mysqlUpsert);
            $setStmt->execute([':k' => 'store_name', ':v' => $storeName, ':now' => $now]);
            $setStmt->execute([':k' => 'store_currency', ':v' => $storeCurrency, ':now' => $now]);
            $setStmt->execute([':k' => 'version', ':v' => '1.0.0', ':now' => $now]);
        } catch (Throwable $e) {
            // Non-fatal if settings table fails
        }

        // 8. Write lockfile
        try {
            $lockDir = dirname($lockPath);
            if (!is_dir($lockDir)) {
                mkdir($lockDir, 0775, true);
            }
            $fingerprint = hash('sha256', $now . ($dbConfig['database'] ?? '') . $adminEmail);
            $lockData = json_encode([
                'installed_at' => $now,
                'version' => '1.0.0',
                'environment' => getenv('APP_ENV') ?: 'production',
                'mode' => !empty($config['unattended']) ? 'unattended' : 'interactive',
                'db_driver' => $driver,
                'admin_email' => $adminEmail,
                'store_name' => $storeName,
                'fingerprint' => $fingerprint,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            file_put_contents($lockPath, $lockData);
        } catch (Throwable $e) {
            return InstallResult::failure(['Failed to write installation lockfile: ' . $e->getMessage()], 1);
        }

        return InstallResult::success(
            'Installation completed successfully.',
            $plainPat,
            $applied
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function resolveLockfilePath(array $config): string
    {
        if (!empty($config['lockfile_path'])) {
            return (string)$config['lockfile_path'];
        }

        $envLock = getenv('INSTALL_LOCK_PATH');
        if (is_string($envLock) && $envLock !== '') {
            return $envLock;
        }

        if (
            isset($_ENV['INSTALL_LOCK_PATH'])
            && is_string($_ENV['INSTALL_LOCK_PATH'])
            && $_ENV['INSTALL_LOCK_PATH'] !== ''
        ) {
            return $_ENV['INSTALL_LOCK_PATH'];
        }

        return $this->baseDir . '/storage/installed.lock';
    }
}
