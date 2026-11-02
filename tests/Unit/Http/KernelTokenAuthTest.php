<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Http;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Http\Kernel;

/**
 * Unit test suite for Kernel Bearer Token SHA-256 Hashing & Scope Resolution.
 * Verifies PROJECT.md Feature #19 and Milestone 3 token security contracts.
 */
class KernelTokenAuthTest extends TestCase
{
    private PDO $pdo;
    private Kernel $kernel;
    private string $tempDbFile;
    private string $tempLockFile;

    private const PLAIN_ADMIN_TOKEN = 'wincart_pat_admin_secret_998877';
    private const PLAIN_CUSTOMER_TOKEN = 'wincart_pat_customer_read_112233';
    private const PLAIN_LEGACY_RAW_TOKEN = 'wincart_pat_legacy_raw_unhashed_5566';
    private const PLAIN_INACTIVE_TOKEN = 'wincart_pat_inactive_secret_445566';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_auth_test_' . uniqid() . '.sqlite';
        $this->tempLockFile = sys_get_temp_dir() . '/wincart_lock_' . uniqid() . '.lock';
        touch($this->tempLockFile);

        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);

        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();

        $this->seedTestCustomers();

        $this->kernel = new Kernel([
            'DB_DATABASE' => $this->tempDbFile,
            'INSTALL_LOCK_PATH' => $this->tempLockFile,
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbFile)) {
            unlink($this->tempDbFile);
        }
        if (file_exists($this->tempLockFile)) {
            unlink($this->tempLockFile);
        }
        parent::tearDown();
    }

    private function seedTestCustomers(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers ('
            . 'id, email, password_hash, first_name, last_name, role, pat_token_hash, pat_scopes, is_active'
            . ') VALUES (:id, :email, :pass, :fname, :lname, :role, :tok, :scopes, :active)'
        );

        // 1. Admin with SHA-256 hashed token
        $stmt->execute([
            ':id' => 901,
            ':email' => 'admin.hashed@wincart.local',
            ':pass' => password_hash('secret', PASSWORD_BCRYPT),
            ':fname' => 'Admin',
            ':lname' => 'Hashed',
            ':role' => 'admin',
            ':tok' => hash('sha256', self::PLAIN_ADMIN_TOKEN),
            ':scopes' => 'admin,read,write',
            ':active' => 1,
        ]);

        // 2. Read-only Customer with SHA-256 hashed token
        $stmt->execute([
            ':id' => 902,
            ':email' => 'customer.read@wincart.local',
            ':pass' => password_hash('secret', PASSWORD_BCRYPT),
            ':fname' => 'Customer',
            ':lname' => 'ReadOnly',
            ':role' => 'customer',
            ':tok' => hash('sha256', self::PLAIN_CUSTOMER_TOKEN),
            ':scopes' => 'read',
            ':active' => 1,
        ]);

        // 3. Legacy Customer with unhashed raw token (backward compatibility)
        $stmt->execute([
            ':id' => 903,
            ':email' => 'legacy.raw@wincart.local',
            ':pass' => password_hash('secret', PASSWORD_BCRYPT),
            ':fname' => 'Legacy',
            ':lname' => 'Raw',
            ':role' => 'admin',
            ':tok' => self::PLAIN_LEGACY_RAW_TOKEN,
            ':scopes' => 'admin,read,write',
            ':active' => 1,
        ]);

        // 4. Inactive Customer with valid SHA-256 hashed token
        $stmt->execute([
            ':id' => 904,
            ':email' => 'inactive.user@wincart.local',
            ':pass' => password_hash('secret', PASSWORD_BCRYPT),
            ':fname' => 'Inactive',
            ':lname' => 'User',
            ':role' => 'admin',
            ':tok' => hash('sha256', self::PLAIN_INACTIVE_TOKEN),
            ':scopes' => 'admin,read,write',
            ':active' => 0,
        ]);
    }

    /**
     * Test 1: Customer with SHA-256 hashed token successfully authenticates when presenting plaintext token.
     */
    public function testSha256HashedTokenAuthenticatesSuccessfully(): void
    {
        $response = $this->kernel->handleRequest(
            'GET',
            '/api/v1/orders',
            null,
            ['Authorization' => 'Bearer ' . self::PLAIN_ADMIN_TOKEN]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 2: Backward compatibility — legacy customer with unhashed raw token authenticates.
     */
    public function testLegacyRawUnhashedTokenAuthenticatesForBackwardCompatibility(): void
    {
        $response = $this->kernel->handleRequest(
            'GET',
            '/api/v1/orders',
            null,
            ['Authorization' => 'Bearer ' . self::PLAIN_LEGACY_RAW_TOKEN]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 3: Completely invalid / unrecognized token is rejected with 401 Unauthorized.
     */
    public function testInvalidTokenRejectedWith401Unauthorized(): void
    {
        $response = $this->kernel->handleRequest(
            'GET',
            '/api/v1/orders',
            null,
            ['Authorization' => 'Bearer non_existent_token_string_12345']
        );

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Unauthorized', $body['title'] ?? null);
        $this->assertSame('Authentication token required', $body['detail'] ?? null);
    }

    /**
     * Test 4: Token that differs by 1 character produces different hash and is rejected with 401.
     */
    public function testSingleCharacterTamperedTokenRejectedWith401(): void
    {
        $tamperedToken = self::PLAIN_ADMIN_TOKEN . 'x';
        $response = $this->kernel->handleRequest(
            'GET',
            '/api/v1/orders',
            null,
            ['Authorization' => 'Bearer ' . $tamperedToken]
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test 5: Inactive customer with matching SHA-256 hashed token is rejected with 401.
     */
    public function testInactiveCustomerTokenRejectedWith401(): void
    {
        $response = $this->kernel->handleRequest(
            'GET',
            '/api/v1/orders',
            null,
            ['Authorization' => 'Bearer ' . self::PLAIN_INACTIVE_TOKEN]
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test 6: Missing Authorization header is rejected with 401.
     */
    public function testMissingAuthorizationHeaderRejectedWith401(): void
    {
        $response = $this->kernel->handleRequest('GET', '/api/v1/orders');
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test 7: Customer with valid SHA-256 token but insufficient scopes is rejected with 403 Forbidden.
     */
    public function testHashedTokenWithInsufficientScopesRejectedWith403(): void
    {
        // Customer 902 has only 'read' scope, attempts DELETE on product
        $response = $this->kernel->handleRequest(
            'DELETE',
            '/api/v1/products/1',
            null,
            ['Authorization' => 'Bearer ' . self::PLAIN_CUSTOMER_TOKEN]
        );

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Forbidden', $body['title'] ?? null);
    }

    /**
     * Test 8: Customer ID resolution binds cart to correct customer ID when authenticated via SHA-256 token.
     */
    public function testCustomerIdResolvedCorrectlyForCartAssociation(): void
    {
        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/cart',
            null,
            ['Authorization' => 'Bearer ' . self::PLAIN_CUSTOMER_TOKEN]
        );

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(902, $body['customer_id'] ?? null);
    }
}
