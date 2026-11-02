<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Common\Money;
use WinCart\Common\StockStatus;

final class UpdateProductTool implements ToolInterface
{
    public function __construct(private ProductRepositoryInterface $productRepo)
    {
    }

    public function getName(): string
    {
        return 'update_product';
    }

    public function getDescription(): string
    {
        return 'Update an existing product\'s fields such as stock quantity, price, title, or status.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Unique product ID to update',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Dictionary of fields to update',
                ],
                'stock_quantity' => [
                    'type' => 'integer',
                    'description' => 'Updated inventory stock quantity',
                ],
                'price' => [
                    'type' => 'integer',
                    'description' => 'Updated price in minor cents',
                ],
                'price_cents' => [
                    'type' => 'integer',
                    'description' => 'Updated price in minor cents',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Updated product name',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Updated product title',
                ],
                'sku' => [
                    'type' => 'string',
                    'description' => 'Updated product SKU',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Updated description',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Updated visibility status',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $id = isset($arguments['id']) ? (int)$arguments['id'] : 0;
        if ($id <= 0) {
            return ToolResult::text("Error: Missing or invalid product 'id'", isError: true);
        }

        $product = $this->productRepo->findById($id);
        if ($product === null) {
            return ToolResult::text("Error: Product #{$id} not found", isError: true);
        }

        $updates = is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];
        foreach ($arguments as $k => $v) {
            if ($k !== 'id' && $k !== 'fields') {
                $updates[$k] = $v;
            }
        }

        $name = isset($updates['name']) || isset($updates['title'])
            ? (string)($updates['name'] ?? $updates['title'])
            : $product->getName();

        $sku = isset($updates['sku']) ? (string)$updates['sku'] : $product->getSku();

        $price = isset($updates['price']) || isset($updates['price_cents'])
            ? new Money((int)($updates['price'] ?? $updates['price_cents']), 'USD')
            : $product->getPrice();

        $stockQuantity = isset($updates['stock_quantity'])
            ? (int)$updates['stock_quantity']
            : $product->getStockQuantity();

        $stockStatus = StockStatus::fromQuantity($stockQuantity, $product->isBackordersAllowed());

        $description = isset($updates['description']) ? (string)$updates['description'] : $product->getDescription();
        $isActive = isset($updates['is_active']) ? (bool)$updates['is_active'] : $product->isActive();

        $updatedProduct = new Product(
            id: $product->getId(),
            name: $name,
            slug: $product->getSlug(),
            sku: $sku,
            type: $product->getType(),
            price: $price,
            salePrice: $product->getSalePrice(),
            compareAtPrice: $product->getCompareAtPrice(),
            costPrice: $product->getCostPrice(),
            description: $description,
            shortDescription: $product->getShortDescription(),
            stockQuantity: $stockQuantity,
            stockStatus: $stockStatus,
            manageStock: $product->isManageStock(),
            backordersAllowed: $product->isBackordersAllowed(),
            requiresShipping: $product->requiresShipping(),
            weight: $product->getWeight(),
            isActive: $isActive,
            categoryIds: $product->getCategoryIds(),
            variations: $product->getVariations(),
            images: $product->getImages()
        );

        $saved = $this->productRepo->save($updatedProduct);
        return ToolResult::json($saved->toArray());
    }
}
