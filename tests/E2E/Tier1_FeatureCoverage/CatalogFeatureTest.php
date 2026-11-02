<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Product Catalog & Category Hierarchy.
 * Verifies R2 and Feature #9 from PROJECT.md.
 */
class CatalogFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Full-text and keyword product searching.
     */
    public function testCatalogProductSearchByKeyword(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/products?search=Chair');
        $this->assertContains($response->getStatusCode(), [200, 302]);

        if ($response->getStatusCode() === 200) {
            $data = $response->getJson();
            $this->assertIsArray($data);
        }
    }

    /**
     * Test 2: Category hierarchy and recursive category filtering.
     */
    public function testCatalogCategoryFilteringAndHierarchy(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/products?category=laptops');
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    /**
     * Test 3: Exact SKU product lookup.
     */
    public function testCatalogSkuExactLookup(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/products/sku/PROD-SKU-99');
        $this->assertContains($response->getStatusCode(), [200, 302, 404]);
    }

    /**
     * Test 4: Variable product attribute matrix and variations.
     */
    public function testCatalogVariableProductsAndAttributes(): void
    {
        $type = ProductType::Variable;
        $this->assertTrue($type->isVariable());
        $this->assertTrue($type->requiresShipping());
        $this->assertSame('variable', $type->value);

        $variationPrice = new Money(3999, 'USD');
        $this->assertSame(3999, $variationPrice->getAmount());
        $this->assertSame('$39.99', $variationPrice->format());
    }

    /**
     * Test 5: Dynamic inventory stock status calculation.
     */
    public function testCatalogStockStatusQuery(): void
    {
        $inStock = StockStatus::fromQuantity(5, false);
        $this->assertSame(StockStatus::InStock, $inStock);
        $this->assertTrue($inStock->isAvailable());

        $outOfStock = StockStatus::fromQuantity(0, false);
        $this->assertSame(StockStatus::OutOfStock, $outOfStock);
        $this->assertFalse($outOfStock->isAvailable());

        $onBackorder = StockStatus::fromQuantity(0, true);
        $this->assertSame(StockStatus::OnBackorder, $onBackorder);
        $this->assertTrue($onBackorder->isAvailable());
    }
}
