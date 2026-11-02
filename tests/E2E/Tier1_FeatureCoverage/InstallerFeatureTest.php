<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Automated Quick Installer (CLI & Web Wizard).
 * Verifies R4 and Features #22, #23, #25, #26 from PROJECT.md.
 */
class InstallerFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Unattended CLI installation with SQLite database.
     */
    public function testCliInstallerUnattendedSqliteInstall(): void
    {
        $this->requireCli();
        $this->assertFalse($this->env->hasLockfile());

        $result = $this->cli->run('install', [], [
            'db-driver' => 'sqlite',
            'db-path' => $this->env->getDatabasePath(),
            'admin-email' => 'admin@wincart.local',
            'admin-user' => 'admin',
            'admin-pass' => 'Secret123!',
            'site-name' => 'Test Store',
            'currency' => 'USD',
            'non-interactive' => true,
        ]);

        $this->assertSame(0, $result->getExitCode(), 'CLI installer failed: ' . $result->getStderr());
        $this->assertTrue($this->env->hasLockfile(), 'Expected storage/installed.lock to exist post-install');
        $this->assertStringContainsString('Installation completed successfully', $result->getOutput());
    }

    /**
     * Test 2: Web Wizard Step 1 environment validation check.
     */
    public function testWebWizardEnvironmentCheck(): void
    {
        $this->requireHttp();
        $response = $this->http->get('/install?step=1');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('PHP Version', $body);
        $this->assertStringContainsString('storage/ is writable', $body);
    }

    /**
     * Test 3: Web Wizard administrator user provisioning.
     */
    public function testWebWizardAdminUserCreation(): void
    {
        $this->requireHttp();
        $response = $this->http->post('/install/admin', [
            'username' => 'superadmin',
            'email' => 'admin@wincart.local',
            'password' => 'Pass12345!',
            'password_confirmation' => 'Pass12345!',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        if ($response->isRedirect()) {
            $this->assertStringContainsString('/install', $response->getHeaderLine('Location'));
        }
    }

    /**
     * Test 4: Demo catalog data seeding validation.
     */
    public function testInstallerDemoCatalogSeeding(): void
    {
        $this->requireCli();
        $result = $this->cli->run('install', [], [
            'db-driver' => 'sqlite',
            'db-path' => $this->env->getDatabasePath(),
            'seed' => true,
            'non-interactive' => true,
        ]);

        $this->assertSame(0, $result->getExitCode(), 'CLI demo seeding failed: ' . $result->getStderr());
        $this->assertTrue($this->env->hasLockfile());
    }

    /**
     * Test 5: Lockfile security guard blocks access post-installation with HTTP 403.
     */
    public function testInstallerLockfileGuardBlocksReinstallation(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();
        $this->assertTrue($this->env->hasLockfile());

        $getResponse = $this->http->get('/install');
        $this->assertSame(403, $getResponse->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('locked', $getResponse->getBody());

        $postResponse = $this->http->post('/install/database', [
            'db_name' => 'malicious_overwrite.sqlite',
        ]);
        $this->assertSame(403, $postResponse->getStatusCode());
    }
}
