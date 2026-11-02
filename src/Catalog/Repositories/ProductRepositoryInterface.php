<?php

declare(strict_types=1);

namespace WinCart\Catalog\Repositories;

use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Models\Variation;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;

    public function findBySlug(string $slug): ?Product;

    public function findBySku(string $sku): ?Product;

    public function findVariationById(int $variationId): ?Variation;

    /**
     * @return array<Variation>
     */
    public function findVariationsByProductId(int $productId): array;

    /**
     * @param array<string, mixed> $criteria search, category_id, category_slug, type, stock_status, min_price
     * @return array<Product>
     */
    public function search(array $criteria, int $limit = 20, int $offset = 0): array;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria): int;

    public function save(Product $product): Product;

    public function delete(int $id): bool;

    /**
     * Atomically adjust inventory stock by delta.
     * Prevents race conditions and negative inventory via atomic SQL guard:
     * UPDATE products SET stock_quantity = stock_quantity + :delta ... WHERE stock_quantity + :delta >= 0
     *
     * @param int $productId Parent product ID
     * @param int $delta Quantity change (positive to restock, negative to deduct)
     * @param string $reason Operational context ('checkout', 'cancellation', 'refund', 'restock')
     * @param int|null $variationId Specific variation ID if adjusting variable product
     * @return int New remaining stock quantity
     * @throws \WinCart\Catalog\Exceptions\InsufficientStockException
     * @throws \WinCart\Catalog\Exceptions\ProductNotFoundException
     */
    public function adjustStock(int $productId, int $delta, string $reason, ?int $variationId = null): int;
}
