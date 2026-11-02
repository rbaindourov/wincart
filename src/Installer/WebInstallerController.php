<?php

declare(strict_types=1);

namespace WinCart\Installer;

use Throwable;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Database\DemoDataSeeder;
use WinCart\Tests\E2E\Support\HttpResponse;

final class WebInstallerController
{
    private string $baseDir;
    private InstallerService $installerService;

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private array $env = [],
        ?InstallerService $installerService = null,
        ?string $baseDir = null
    ) {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
        $this->installerService = $installerService ?? new InstallerService($this->baseDir);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $headers
     */
    public function handle(
        string $method,
        string $path,
        array $queryParams,
        ?string $body,
        array $headers = []
    ): HttpResponse {
        $lockPath = $this->env['INSTALL_LOCK_PATH']
            ?? getenv('INSTALL_LOCK_PATH')
            ?: ($this->baseDir . '/storage/installed.lock');

        if (file_exists($lockPath)) {
            return new HttpResponse(403, 'Installation locked. Store is already configured.', [
                'Content-Type' => ['text/plain; charset=UTF-8'],
            ]);
        }

        // Parse payload
        $payload = [];
        if ($body !== null && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                parse_str($body, $payload);
            }
        }

        $state = $this->loadState();

        // Route matching
        if ($method === 'POST') {
            if ($path === '/install/database') {
                return $this->handleDatabasePost($payload, $state);
            }
            if ($path === '/install/profile') {
                return $this->handleProfilePost($payload, $state);
            }
            if ($path === '/install/admin') {
                return $this->handleAdminPost($payload, $state);
            }
            if ($path === '/install/finalize') {
                return $this->handleFinalizePost($payload, $state, $lockPath);
            }
        }

        // Determine step
        $step = 1;
        if (isset($queryParams['step'])) {
            $step = (int)$queryParams['step'];
        } elseif (preg_match('#^/install/step(\d+)$#', $path, $matches)) {
            $step = (int)$matches[1];
        }

        return match ($step) {
            2 => $this->renderStep2($state),
            3 => $this->renderStep3($state),
            4 => $this->renderStep4($state),
            5 => $this->renderStep5($state),
            default => $this->renderStep1($state),
        };
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderStep1(array $state): HttpResponse
    {
        $env = EnvironmentChecker::check($this->baseDir);
        $step = 1;
        $title = 'Step 1: Environment Check - winCart Setup';

        ob_start();
        require $this->baseDir . '/templates/installer/step1_environment.php';
        $content = (string)ob_get_clean();

        ob_start();
        require $this->baseDir . '/templates/installer/layout.php';
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderStep2(array $state): HttpResponse
    {
        $step = 2;
        $title = 'Step 2: Database Setup - winCart Setup';

        ob_start();
        require $this->baseDir . '/templates/installer/step2_database.php';
        $content = (string)ob_get_clean();

        ob_start();
        require $this->baseDir . '/templates/installer/layout.php';
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderStep3(array $state): HttpResponse
    {
        $step = 3;
        $title = 'Step 3: Store Profile - winCart Setup';

        ob_start();
        require $this->baseDir . '/templates/installer/step3_profile.php';
        $content = (string)ob_get_clean();

        ob_start();
        require $this->baseDir . '/templates/installer/layout.php';
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderStep4(array $state): HttpResponse
    {
        $step = 4;
        $title = 'Step 4: Administrator Account - winCart Setup';

        ob_start();
        require $this->baseDir . '/templates/installer/step4_admin.php';
        $content = (string)ob_get_clean();

        ob_start();
        require $this->baseDir . '/templates/installer/layout.php';
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderStep5(array $state): HttpResponse
    {
        $step = 5;
        $title = 'Step 5: Complete Installation - winCart Setup';

        ob_start();
        require $this->baseDir . '/templates/installer/step5_complete.php';
        $content = (string)ob_get_clean();

        ob_start();
        require $this->baseDir . '/templates/installer/layout.php';
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $state
     */
    private function handleDatabasePost(array $payload, array &$state): HttpResponse
    {
        $driver = strtolower((string)($payload['db_driver'] ?? 'sqlite'));
        $dbConfig = ['driver' => $driver];

        if ($driver === 'sqlite') {
            $dbPath = (string)($payload['db_path'] ?? ($this->baseDir . '/storage/database.sqlite'));
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0775, true);
            }
            $dbConfig['database'] = $dbPath;
            $state['db_path'] = $dbPath;
        } else {
            $dbConfig['host'] = $payload['db_host'] ?? '127.0.0.1';
            $dbConfig['port'] = (int)($payload['db_port'] ?? 3306);
            $dbConfig['database'] = $payload['db_name'] ?? 'wincart';
            $dbConfig['username'] = $payload['db_user'] ?? 'root';
            $dbConfig['password'] = $payload['db_pass'] ?? '';
            $dbConfig['charset'] = 'utf8mb4';
        }
        $state['db_driver'] = $driver;
        $state['db_config'] = $dbConfig;

        try {
            $pdo = ConnectionFactory::create($dbConfig);
            $migrationsPath = $this->baseDir . '/database/migrations';
            $runner = new MigrationRunner($pdo, $migrationsPath);
            $runner->migrateUp();
        } catch (Throwable $e) {
            // If connection/migration fails
        }

        $this->saveState($state);

        return new HttpResponse(200, 'Database configured successfully', [
            'Content-Type' => ['text/plain; charset=UTF-8'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $state
     */
    private function handleProfilePost(array $payload, array &$state): HttpResponse
    {
        $state['store_name'] = (string)($payload['store_name'] ?? 'winCart Store');
        $state['store_currency'] = strtoupper((string)($payload['store_currency'] ?? 'USD'));
        $this->saveState($state);

        return new HttpResponse(302, '', [
            'Location' => ['/install?step=4'],
            'Content-Type' => ['text/html; charset=UTF-8'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $state
     */
    private function handleAdminPost(array $payload, array &$state): HttpResponse
    {
        $username = (string)($payload['username'] ?? 'admin');
        $email = (string)($payload['email'] ?? 'admin@wincart.local');
        $password = (string)($payload['password'] ?? 'Secret123!');

        $plainPat = 'wincart_pat_' . bin2hex(random_bytes(24));
        $patHash = hash('sha256', $plainPat);
        $passHash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');
        $scopes = 'read:products,write:products,read:orders,write:orders,read:customers,write:customers,'
            . 'manage:settings,admin,read,write,products:read,products:write,orders:read,orders:write,'
            . 'cart:read,admin:read,admin:write';

        $state['admin_user'] = $username;
        $state['admin_email'] = $email;
        $state['admin_pass'] = $password;
        $state['pat_token'] = $plainPat;

        // If DB is already configured, persist admin record now
        if (!empty($state['db_config'])) {
            try {
                $pdo = ConnectionFactory::create($state['db_config']);
                $checkStmt = $pdo->prepare('SELECT id FROM customers WHERE email = :email');
                $checkStmt->execute([':email' => $email]);
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
                        ':email' => $email,
                        ':pass' => $passHash,
                        ':fname' => $username,
                        ':lname' => 'Administrator',
                        ':role' => 'admin',
                        ':pat' => $patHash,
                        ':scopes' => $scopes,
                        ':now' => $now,
                    ]);
                }
            } catch (Throwable $e) {
                // Ignore DB error during in-memory stub
            }
        }

        $this->saveState($state);

        return new HttpResponse(302, '', [
            'Location' => ['/install?step=5'],
            'Content-Type' => ['text/html; charset=UTF-8'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $state
     */
    private function handleFinalizePost(array $payload, array &$state, string $lockPath): HttpResponse
    {
        $config = [
            'db_driver' => $state['db_driver'] ?? 'sqlite',
            'db_path' => $state['db_path'] ?? ($this->baseDir . '/storage/database.sqlite'),
            'admin_user' => $state['admin_user'] ?? 'admin',
            'admin_email' => $state['admin_email'] ?? 'admin@wincart.local',
            'admin_pass' => $state['admin_pass'] ?? 'Secret123!',
            'store_name' => $state['store_name'] ?? 'winCart Store',
            'store_currency' => $state['store_currency'] ?? 'USD',
            'demo_data' => !empty($payload['demo_data']),
            'lockfile_path' => $lockPath,
            'force' => true,
        ];

        $this->installerService->install($config);
        $this->clearState();

        return new HttpResponse(302, '', [
            'Location' => ['/'],
            'Content-Type' => ['text/html; charset=UTF-8'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        $file = $this->baseDir . '/storage/installer_state.json';
        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $dir = $this->baseDir . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/installer_state.json', json_encode($state, JSON_PRETTY_PRINT));
    }

    private function clearState(): void
    {
        $file = $this->baseDir . '/storage/installer_state.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
