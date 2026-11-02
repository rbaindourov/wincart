<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;

class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
        $this->runner = new MigrationRunner($this->pdo);
    }

    public function testMigrationsTableIsBootstrapped(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");
        $this->assertSame('migrations', $stmt->fetchColumn());
    }

    public function testMigrateUpAppliesAllFourMigrationFiles(): void
    {
        $applied = $this->runner->migrateUp();
        $this->assertCount(4, $applied);
        $this->assertContains('001_create_catalog_tables.sql', $applied);
        $this->assertContains('002_create_customer_address_tables.sql', $applied);
        $this->assertContains('003_create_cart_and_pricing_tables.sql', $applied);
        $this->assertContains('004_create_order_tables.sql', $applied);

        // Verify that running migrateUp again is idempotent (0 pending)
        $secondRun = $this->runner->migrateUp();
        $this->assertEmpty($secondRun);

        // Verify all 16 tables exist
        $query = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
        $tables = $this->pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);

        $expectedTables = [
            'migrations',
            'categories',
            'products',
            'product_attributes',
            'product_attribute_values',
            'product_variations',
            'product_categories',
            'product_images',
            'customers',
            'addresses',
            'tax_zones',
            'coupons',
            'carts',
            'cart_items',
            'orders',
            'order_items',
        ];

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tables, "Expected table {$table} to exist");
        }
        $this->assertCount(16, $tables);
    }

    public function testRollbackLastBatch(): void
    {
        $this->runner->migrateUp();
        $status = $this->runner->getStatus();
        $this->assertCount(4, $status);
        foreach ($status as $s) {
            $this->assertTrue($s['applied']);
            $this->assertSame(1, $s['batch']);
        }

        $rolledBack = $this->runner->rollbackLastBatch();
        $this->assertCount(4, $rolledBack);

        $statusAfter = $this->runner->getStatus();
        foreach ($statusAfter as $s) {
            $this->assertFalse($s['applied']);
        }
    }

    public function testTransactionalWrapper(): void
    {
        $this->runner->migrateUp();

        $result = ConnectionFactory::transactional($this->pdo, function (PDO $pdo) {
            $pdo->exec("INSERT INTO categories (name, slug) VALUES ('Test Cat', 'test-cat')");
            return 42;
        });

        $this->assertSame(42, $result);
        $count = $this->pdo->query("SELECT COUNT(*) FROM categories WHERE slug='test-cat'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }
}
