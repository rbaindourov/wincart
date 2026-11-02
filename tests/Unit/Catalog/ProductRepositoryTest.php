<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Catalog;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Models\Category;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoCategoryRepository;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;

class ProductRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoProductRepository $productRepo;
    private PdoCategoryRepository $categoryRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();

        $this->productRepo = new PdoProductRepository($this->pdo);
        $this->categoryRepo = new PdoCategoryRepository($this->pdo);
    }

    public function testSaveAndFindProduct(): void
    {
        $product = new Product(
            id: null,
            name: 'Studio Turntable',
            slug: 'studio-turntable',
            sku: 'TT-01',
            type: ProductType::Simple,
            price: new Money(34900, 'USD'),
            salePrice: null,
            compareAtPrice: null,
            costPrice: null,
            description: 'Direct-drive turntable',
            shortDescription: 'HiFi Audio Turntable',
            stockQuantity: 10,
            stockStatus: StockStatus::InStock
        );

        $saved = $this->productRepo->save($product);
        $this->assertNotNull($saved->getId());
        $this->assertSame('studio-turntable', $saved->getSlug());
        $this->assertSame('TT-01', $saved->getSku());
        $this->assertSame(34900, $saved->getPrice()->getAmount());

        $foundById = $this->productRepo->findById($saved->getId());
        $this->assertNotNull($foundById);
        $this->assertSame('Studio Turntable', $foundById->getName());

        $foundBySlug = $this->productRepo->findBySlug('studio-turntable');
        $this->assertNotNull($foundBySlug);
        $this->assertSame($saved->getId(), $foundBySlug->getId());

        $foundBySku = $this->productRepo->findBySku('TT-01');
        $this->assertNotNull($foundBySku);
        $this->assertSame($saved->getId(), $foundBySku->getId());
    }

    public function testAtomicStockDecrementAndOversellGuard(): void
    {
        $product = new Product(
            id: null,
            name: 'Rare Vinyl',
            slug: 'rare-vinyl',
            sku: 'VINYL-01',
            type: ProductType::Simple,
            price: new Money(4500, 'USD'),
            stockQuantity: 2,
            stockStatus: StockStatus::InStock
        );

        $saved = $this->productRepo->save($product);
        $id = (int)$saved->getId();

        // 1. Valid deduction of 1 unit
        $newStock = $this->productRepo->adjustStock($id, -1, 'checkout');
        $this->assertSame(1, $newStock);

        $reloaded = $this->productRepo->findById($id);
        $this->assertSame(1, $reloaded->getStockQuantity());
        $this->assertSame(StockStatus::InStock, $reloaded->getStockStatus());

        // 2. Deduction of 1 unit drains stock to 0
        $newStock2 = $this->productRepo->adjustStock($id, -1, 'checkout');
        $this->assertSame(0, $newStock2);

        $reloaded2 = $this->productRepo->findById($id);
        $this->assertSame(0, $reloaded2->getStockQuantity());
        $this->assertSame(StockStatus::OutOfStock, $reloaded2->getStockStatus());

        // 3. Overselling attempt: stock is 0, trying to deduct 1 throws InsufficientStockException
        $this->expectException(InsufficientStockException::class);
        $this->productRepo->adjustStock($id, -1, 'checkout');
    }

    public function testRestockRestoresInventory(): void
    {
        $product = new Product(
            id: null,
            name: 'Headphones',
            slug: 'headphones',
            sku: 'HP-01',
            type: ProductType::Simple,
            price: new Money(15000, 'USD'),
            stockQuantity: 0,
            stockStatus: StockStatus::OutOfStock
        );

        $saved = $this->productRepo->save($product);
        $id = (int)$saved->getId();

        $newStock = $this->productRepo->adjustStock($id, 5, 'restock');
        $this->assertSame(5, $newStock);

        $reloaded = $this->productRepo->findById($id);
        $this->assertSame(5, $reloaded->getStockQuantity());
        $this->assertSame(StockStatus::InStock, $reloaded->getStockStatus());
    }

    public function testCategoryHierarchyAndSubtree(): void
    {
        $root = $this->categoryRepo->save(new Category(
            id: null,
            name: 'Audio',
            slug: 'audio'
        ));

        $sub = $this->categoryRepo->save(new Category(
            id: null,
            name: 'Turntables',
            slug: 'turntables',
            parentId: $root->getId()
        ));

        $subSub = $this->categoryRepo->save(new Category(
            id: null,
            name: 'Cartridges',
            slug: 'cartridges',
            parentId: $sub->getId()
        ));

        $tree = $this->categoryRepo->findTree();
        $this->assertCount(1, $tree);
        $this->assertSame('Audio', $tree[0]->getName());
        $this->assertCount(1, $tree[0]->getChildren());
        $this->assertSame('Turntables', $tree[0]->getChildren()[0]->getName());

        $subtreeIds = $this->categoryRepo->findSubtreeIds((int)$root->getId());
        $this->assertContains($root->getId(), $subtreeIds);
        $this->assertContains($sub->getId(), $subtreeIds);
        $this->assertContains($subSub->getId(), $subtreeIds);
    }
}
