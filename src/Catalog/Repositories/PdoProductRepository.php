<?php

declare(strict_types=1);

namespace WinCart\Catalog\Repositories;

use DateTimeImmutable;
use PDO;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Exceptions\ProductNotFoundException;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Models\Variation;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

final class PdoProductRepository implements ProductRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateProduct($row) : null;
    }

    public function findBySlug(string $slug): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateProduct($row) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateProduct($row) : null;
    }

    public function findVariationById(int $variationId): ?Variation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_variations WHERE id = :id');
        $stmt->execute(['id' => $variationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateVariation($row) : null;
    }

    /**
     * @return array<Variation>
     */
    public function findVariationsByProductId(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_variations WHERE product_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->hydrateVariation($r), $rows);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<Product>
     */
    public function search(array $criteria, int $limit = 20, int $offset = 0): array
    {
        [$whereSql, $params] = $this->buildWhereClause($criteria);

        $sql = "SELECT p.* FROM products p {$whereSql} ORDER BY p.id ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->hydrateProduct($r), $rows);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria): int
    {
        [$whereSql, $params] = $this->buildWhereClause($criteria);
        $sql = "SELECT COUNT(*) FROM products p {$whereSql}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function save(Product $product): Product
    {
        $now = date('Y-m-d H:i:s');

        if ($product->getId() === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (
                    name, slug, sku, product_type, price, sale_price, compare_at_price, cost_price,
                    description, short_description, stock_quantity, stock_status, manage_stock,
                    backorders_allowed, requires_shipping, weight, is_active, created_at, updated_at
                ) VALUES (
                    :name, :slug, :sku, :product_type, :price, :sale_price, :compare_at_price, :cost_price,
                    :description, :short_description, :stock_quantity, :stock_status, :manage_stock,
                    :backorders_allowed, :requires_shipping, :weight, :is_active, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                'name' => $product->getName(),
                'slug' => $product->getSlug(),
                'sku' => $product->getSku(),
                'product_type' => $product->getType()->value,
                'price' => $product->getPrice()->getAmount(),
                'sale_price' => $product->getSalePrice()?->getAmount(),
                'compare_at_price' => $product->getCompareAtPrice()?->getAmount(),
                'cost_price' => $product->getCostPrice()?->getAmount(),
                'description' => $product->getDescription(),
                'short_description' => $product->getShortDescription(),
                'stock_quantity' => $product->getStockQuantity(),
                'stock_status' => $product->getStockStatus()->value,
                'manage_stock' => $product->isManageStock() ? 1 : 0,
                'backorders_allowed' => $product->isBackordersAllowed() ? 1 : 0,
                'requires_shipping' => $product->requiresShipping() ? 1 : 0,
                'weight' => $product->getWeight(),
                'is_active' => $product->isActive() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int)$this->pdo->lastInsertId();
            return $this->findById($id) ?? $product;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE products SET
                name = :name, slug = :slug, sku = :sku, product_type = :product_type,
                price = :price, sale_price = :sale_price, compare_at_price = :compare_at_price,
                cost_price = :cost_price, description = :description, short_description = :short_description,
                stock_quantity = :stock_quantity, stock_status = :stock_status, manage_stock = :manage_stock,
                backorders_allowed = :backorders_allowed, requires_shipping = :requires_shipping,
                weight = :weight, is_active = :is_active, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $product->getId(),
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'sku' => $product->getSku(),
            'product_type' => $product->getType()->value,
            'price' => $product->getPrice()->getAmount(),
            'sale_price' => $product->getSalePrice()?->getAmount(),
            'compare_at_price' => $product->getCompareAtPrice()?->getAmount(),
            'cost_price' => $product->getCostPrice()?->getAmount(),
            'description' => $product->getDescription(),
            'short_description' => $product->getShortDescription(),
            'stock_quantity' => $product->getStockQuantity(),
            'stock_status' => $product->getStockStatus()->value,
            'manage_stock' => $product->isManageStock() ? 1 : 0,
            'backorders_allowed' => $product->isBackordersAllowed() ? 1 : 0,
            'requires_shipping' => $product->requiresShipping() ? 1 : 0,
            'weight' => $product->getWeight(),
            'is_active' => $product->isActive() ? 1 : 0,
            'updated_at' => $now,
        ]);

        return $this->findById($product->getId()) ?? $product;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function adjustStock(int $productId, int $delta, string $reason, ?int $variationId = null): int
    {
        $table = $variationId !== null ? 'product_variations' : 'products';
        $targetId = $variationId !== null ? $variationId : $productId;

        // Atomic SQL update guarded against negative balance
        $sql = "UPDATE {$table}
                SET stock_quantity = stock_quantity + :delta,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND stock_quantity + :delta >= 0";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'delta' => $delta,
            'id' => $targetId,
        ]);

        if ($stmt->rowCount() === 0) {
            $checkStmt = $this->pdo->prepare("SELECT id, stock_quantity, manage_stock FROM {$table} WHERE id = :id");
            $checkStmt->execute(['id' => $targetId]);
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new ProductNotFoundException(
                    sprintf('%s #%d not found', $variationId !== null ? 'Product variation' : 'Product', $targetId)
                );
            }

            $currentStock = (int)$row['stock_quantity'];
            throw new InsufficientStockException(
                sprintf(
                    'Insufficient stock for %s #%d: requested %d units, %d available (reason: %s)',
                    $variationId !== null ? 'variation' : 'product',
                    $targetId,
                    abs($delta),
                    $currentStock,
                    $reason
                )
            );
        }

        $queryStmt = $this->pdo->prepare("SELECT stock_quantity, manage_stock FROM {$table} WHERE id = :id");
        $queryStmt->execute(['id' => $targetId]);
        $updated = $queryStmt->fetch(PDO::FETCH_ASSOC);
        $newStock = (int)$updated['stock_quantity'];

        $newStatus = StockStatus::fromQuantity($newStock, false)->value;
        $statusStmt = $this->pdo->prepare("UPDATE {$table} SET stock_status = :status WHERE id = :id");
        $statusStmt->execute(['status' => $newStatus, 'id' => $targetId]);

        return $newStock;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereClause(array $criteria): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($criteria['search']) && trim((string)$criteria['search']) !== '') {
            $term = '%' . trim((string)$criteria['search']) . '%';
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku OR p.description LIKE :search_desc)';
            $params[':search_name'] = $term;
            $params[':search_sku'] = $term;
            $params[':search_desc'] = $term;
        }

        if (isset($criteria['category_slug']) || isset($criteria['category'])) {
            $catSlug = (string)($criteria['category_slug'] ?? $criteria['category']);
            $where[] = 'p.id IN (
                SELECT pc.product_id FROM product_categories pc
                JOIN categories c ON c.id = pc.category_id
                WHERE c.slug = :cat_slug
            )';
            $params[':cat_slug'] = $catSlug;
        } elseif (isset($criteria['category_id'])) {
            $catId = (int)$criteria['category_id'];
            $where[] = 'p.id IN (SELECT product_id FROM product_categories WHERE category_id = :cat_id)';
            $params[':cat_id'] = $catId;
        }

        if (isset($criteria['type'])) {
            $where[] = 'p.product_type = :type';
            $params[':type'] = (string)$criteria['type'];
        }

        if (isset($criteria['stock_status'])) {
            $where[] = 'p.stock_status = :stock_status';
            $params[':stock_status'] = (string)$criteria['stock_status'];
        }

        if (isset($criteria['is_active'])) {
            $where[] = 'p.is_active = :is_active';
            $params[':is_active'] = $criteria['is_active'] ? 1 : 0;
        }

        if (isset($criteria['min_price'])) {
            $where[] = 'p.price >= :min_price';
            $params[':min_price'] = (int)$criteria['min_price'];
        }

        if (isset($criteria['max_price'])) {
            $where[] = 'p.price <= :max_price';
            $params[':max_price'] = (int)$criteria['max_price'];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateProduct(array $row): Product
    {
        $id = (int)$row['id'];
        $variations = $this->findVariationsByProductId($id);

        $catStmt = $this->pdo->prepare('SELECT category_id FROM product_categories WHERE product_id = :id');
        $catStmt->execute(['id' => $id]);
        $categoryIds = array_map(fn($v) => (int)$v, $catStmt->fetchAll(PDO::FETCH_COLUMN));

        $imgStmt = $this->pdo->prepare('SELECT url FROM product_images WHERE product_id = :id ORDER BY sort_order ASC');
        $imgStmt->execute(['id' => $id]);
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

        $price = new Money((int)$row['price'], 'USD');
        $salePrice = isset($row['sale_price']) && $row['sale_price'] !== null
            ? new Money((int)$row['sale_price'], 'USD')
            : null;
        $comparePrice = isset($row['compare_at_price']) && $row['compare_at_price'] !== null
            ? new Money((int)$row['compare_at_price'], 'USD')
            : null;
        $costPrice = isset($row['cost_price']) && $row['cost_price'] !== null
            ? new Money((int)$row['cost_price'], 'USD')
            : null;

        $type = ProductType::tryFrom((string)$row['product_type']) ?? ProductType::Simple;
        $rawStatus = str_replace('_', '', strtolower((string)($row['stock_status'] ?? 'instock')));
        $stockStatus = StockStatus::tryFrom($rawStatus) ?? StockStatus::InStock;

        return new Product(
            id: $id,
            name: (string)$row['name'],
            slug: (string)$row['slug'],
            sku: (string)$row['sku'],
            type: $type,
            price: $price,
            salePrice: $salePrice,
            compareAtPrice: $comparePrice,
            costPrice: $costPrice,
            description: isset($row['description']) ? (string)$row['description'] : null,
            shortDescription: isset($row['short_description']) ? (string)$row['short_description'] : null,
            stockQuantity: (int)$row['stock_quantity'],
            stockStatus: $stockStatus,
            manageStock: (bool)($row['manage_stock'] ?? 1),
            backordersAllowed: (bool)($row['backorders_allowed'] ?? 0),
            requiresShipping: (bool)($row['requires_shipping'] ?? 1),
            weight: isset($row['weight']) && $row['weight'] !== null ? (float)$row['weight'] : null,
            isActive: (bool)($row['is_active'] ?? 1),
            categoryIds: $categoryIds,
            variations: $variations,
            images: is_array($images) ? $images : [],
            attributes: [],
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string)$row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateVariation(array $row): Variation
    {
        $price = new Money((int)$row['price'], 'USD');
        $salePrice = isset($row['sale_price']) && $row['sale_price'] !== null
            ? new Money((int)$row['sale_price'], 'USD')
            : null;
        $rawVarStatus = str_replace('_', '', strtolower((string)($row['stock_status'] ?? 'instock')));
        $stockStatus = StockStatus::tryFrom($rawVarStatus) ?? StockStatus::InStock;

        $attrMap = [];
        if (!empty($row['attributes_json'])) {
            $decoded = json_decode((string)$row['attributes_json'], true);
            if (is_array($decoded)) {
                $attrMap = $decoded;
            }
        }

        return new Variation(
            id: (int)$row['id'],
            productId: (int)$row['product_id'],
            sku: (string)$row['sku'],
            price: $price,
            salePrice: $salePrice,
            stockQuantity: (int)$row['stock_quantity'],
            stockStatus: $stockStatus,
            manageStock: (bool)($row['manage_stock'] ?? 1),
            attributeMap: $attrMap,
            imageUrl: null
        );
    }
}
