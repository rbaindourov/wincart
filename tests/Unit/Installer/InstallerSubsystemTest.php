<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use WinCart\Http\SecurityLockfileMiddleware;
use WinCart\Installer\Cli\CliOptionParser;
use WinCart\Installer\EnvironmentChecker;
use WinCart\Installer\InstallerService;
use WinCart\Installer\InstallResult;

final class InstallerSubsystemTest extends TestCase
{
    public function testEnvironmentCheckerReturnsValidDiagnostics(): void
    {
        $diagnostics = EnvironmentChecker::check();

        $this->assertArrayHasKey('all_passed', $diagnostics);
        $this->assertArrayHasKey('php_version', $diagnostics);
        $this->assertArrayHasKey('extensions', $diagnostics);
        $this->assertArrayHasKey('permissions', $diagnostics);

        $this->assertTrue($diagnostics['php_version']['passed']);
        $this->assertGreaterThanOrEqual(80200, PHP_VERSION_ID);
        $this->assertArrayHasKey('pdo', $diagnostics['extensions']);
        $this->assertTrue($diagnostics['extensions']['pdo']['passed']);
    }

    public function testCliOptionParserNormalizesFlagsAndAliases(): void
    {
        $args = [
            '--db-driver=sqlite',
            '--database=storage/custom.sqlite',
            '--site-name=My HiFi Store',
            '--currency=EUR',
            '--seed',
            '-n',
            '-f',
            '--lock-path=storage/custom.lock',
            '--admin-username=customadmin',
            '--email=custom@example.com',
            '--admin-pass=CustomPass123!',
            '--db-pass=SecretDb123!',
        ];

        $options = CliOptionParser::parse($args);

        $this->assertSame('sqlite', $options['db_driver']);
        $this->assertSame('storage/custom.sqlite', $options['db_path']);
        $this->assertSame('My HiFi Store', $options['store_name']);
        $this->assertSame('EUR', $options['store_currency']);
        $this->assertTrue($options['demo_data']);
        $this->assertTrue($options['unattended']);
        $this->assertTrue($options['force']);
        $this->assertSame('storage/custom.lock', $options['lockfile_path']);
        $this->assertSame('customadmin', $options['admin_user']);
        $this->assertSame('custom@example.com', $options['admin_email']);
        $this->assertSame('CustomPass123!', $options['admin_pass']);
        $this->assertSame('SecretDb123!', $options['db_pass']);
    }

    public function testInstallResultValueObject(): void
    {
        $successResult = InstallResult::success('All done', 'pat_token_123', ['001_catalog.sql']);
        $this->assertTrue($successResult->isSuccess());
        $this->assertSame(0, $successResult->getExitCode());
        $this->assertSame('pat_token_123', $successResult->getPatToken());
        $this->assertCount(1, $successResult->getAppliedMigrations());
        $this->assertEmpty($successResult->getErrors());

        $failResult = InstallResult::failure(['Database connection refused', 'Timeout'], 1);
        $this->assertFalse($failResult->isSuccess());
        $this->assertSame(1, $failResult->getExitCode());
        $this->assertNull($failResult->getPatToken());
        $this->assertCount(2, $failResult->getErrors());
        $this->assertStringContainsString('Database connection refused', $failResult->getMessage());
    }

    public function testSecurityLockfileMiddlewareRoutingRules(): void
    {
        $tempLock = sys_get_temp_dir() . '/wincart_test_' . bin2hex(random_bytes(8)) . '.lock';
        if (file_exists($tempLock)) {
            unlink($tempLock);
        }

        $middleware = new SecurityLockfileMiddleware($tempLock);

        // 1. Uninstalled state: non-install paths redirect 302 to /install
        $redirectRes = $middleware->handle('/');
        $this->assertNotNull($redirectRes);
        $this->assertSame(302, $redirectRes->getStatusCode());
        $this->assertSame('/install', $redirectRes->getHeaderLine('Location'));

        // 2. Uninstalled state: /install paths pass through (null)
        $this->assertNull($middleware->handle('/install'));
        $this->assertNull($middleware->handle('/install?step=1'));
        $this->assertNull($middleware->handle('/install/database'));

        // 3. Uninstalled state: static assets pass through (null)
        $this->assertNull($middleware->handle('/assets/style.css'));
        $this->assertNull($middleware->handle('/favicon.ico'));

        // 4. Installed state: /install paths return 403 Forbidden
        file_put_contents($tempLock, '{"installed": true}');
        $lockedRes = $middleware->handle('/install');
        $this->assertNotNull($lockedRes);
        $this->assertSame(403, $lockedRes->getStatusCode());
        $this->assertStringContainsString('Installation locked', $lockedRes->getBody());

        // 5. Installed state: storefront paths pass through (null)
        $this->assertNull($middleware->handle('/'));
        $this->assertNull($middleware->handle('/products/vintage-turntable'));

        unlink($tempLock);
    }

    public function testInstallerServiceDetectsPreExistingLockfileWithoutForce(): void
    {
        $tempDir = sys_get_temp_dir() . '/wincart_install_test_' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0775, true);
        $tempLock = $tempDir . '/installed.lock';
        file_put_contents($tempLock, '{"installed": true}');

        $service = new InstallerService($tempDir);
        $result = $service->install([
            'lockfile_path' => $tempLock,
            'force' => false,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString('already installed', $result->getMessage());

        unlink($tempLock);
        rmdir($tempDir);
    }
}
