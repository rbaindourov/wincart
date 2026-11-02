<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use PDO;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

final class CreateProductTool implements ToolInterface
{
    public function __construct(
        private PDO $pdo,
        private ProductRepositoryInterface $productRepo
    ) {
    }

    public function getName(): string
    {
        return 'create_product';
    }

    public function getDescription(): string
    {
        return 'Create a new product in the store catalog with name, SKU, price, stock, and optional categories.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Product title or name',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Alternative alias for product title or name',
                ],
                'sku' => [
                    'type' => 'string',
                    'description' => 'Unique product SKU code (e.g. "SENS-01")',
                ],
                'price' => [
                    'type' => 'integer',
                    'description' => 'Product price in minor currency units (cents), e.g. 4999 for $49.99',
                ],
                'price_cents' => [
                    'type' => 'integer',
                    'description' => 'Alternative alias for price in minor cents',
                ],
                'stock_quantity' => [
                    'type' => 'integer',
                    'description' => 'Initial inventory quantity',
                    'default' => 0,
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL slug. If omitted, generated automatically from name/title',
                ],
                'product_type' => [
                    'type' => 'string',
                    'description' => 'Product type ("simple", "variable", "downloadable")',
                    'enum' => ['simple', 'variable', 'downloadable'],
                    'default' => 'simple',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of category IDs to assign to this product',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Full product description',
                ],
                'short_description' => [
                    'type' => 'string',
                    'description' => 'Short excerpt summary',
                ],
                'manage_stock' => [
                    'type' => 'boolean',
                    'description' => 'Whether store manages inventory for this product',
                    'default' => true,
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether product is active and visible on storefront',
                    'default' => true,
                ],
            ],
            'required' => ['sku'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $name = trim((string)($arguments['name'] ?? $arguments['title'] ?? ''));
        if ($name === '') {
            return ToolResult::text("Error: Product 'name' or 'title' is required", isError: true);
        }

        $sku = trim((string)($arguments['sku'] ?? ''));
        if ($sku === '') {
            return ToolResult::text("Error: Product 'sku' is required", isError: true);
        }

        $priceVal = $arguments['price'] ?? $arguments['price_cents'] ?? null;
        if ($priceVal === null) {
            return ToolResult::text("Error: Product 'price' or 'price_cents' is required", isError: true);
        }
        $priceCents = (int)$priceVal;

        $stockQuantity = (int)($arguments['stock_quantity'] ?? 0);

        if (!empty($arguments['slug'])) {
            $slug = trim((string)$arguments['slug']);
        } else {
            $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        }

        $typeStr = (string)($arguments['product_type'] ?? $arguments['type'] ?? 'simple');
        $productType = ProductType::tryFrom($typeStr) ?? ProductType::Simple;

        $manageStock = isset($arguments['manage_stock']) ? (bool)$arguments['manage_stock'] : true;
        $isActive = isset($arguments['is_active']) ? (bool)$arguments['is_active'] : true;
        $description = isset($arguments['description']) ? (string)$arguments['description'] : null;
        $shortDescription = isset($arguments['short_description']) ? (string)$arguments['short_description'] : null;

        $categoryIds = [];
        if (isset($arguments['category_ids']) && is_array($arguments['category_ids'])) {
            $categoryIds = array_map('intval', $arguments['category_ids']);
        }

        $product = new Product(
            id: null,
            name: $name,
            slug: $slug,
            sku: $sku,
            type: $productType,
            price: new Money($priceCents, 'USD'),
            salePrice: null,
            compareAtPrice: null,
            costPrice: null,
            description: $description,
            shortDescription: $shortDescription,
            stockQuantity: $stockQuantity,
            stockStatus: StockStatus::fromQuantity($stockQuantity, false),
            manageStock: $manageStock,
            backordersAllowed: false,
            requiresShipping: true,
            weight: null,
            isActive: $isActive,
            categoryIds: $categoryIds
        );

        $saved = $this->productRepo->save($product);

        if (!empty($categoryIds) && $saved->getId() !== null) {
            $catStmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO product_categories (product_id, category_id, is_primary) '
                . 'VALUES (:pid, :cid, :is_prim)'
            );
            $first = true;
            foreach ($categoryIds as $cid) {
                $catStmt->execute([
                    ':pid' => $saved->getId(),
                    ':cid' => $cid,
                    ':is_prim' => $first ? 1 : 0,
                ]);
                $first = false;
            }
            $saved = $this->productRepo->findById($saved->getId()) ?? $saved;
        }

        return ToolResult::json($saved->toArray());
    }
}
