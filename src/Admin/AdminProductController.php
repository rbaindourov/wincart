<?php

declare(strict_types=1);

namespace WinCart\Admin;

use InvalidArgumentException;
use PDO;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

final class AdminProductController
{
    private ProductRepositoryInterface $productRepository;

    public function __construct(private PDO $pdo)
    {
        $this->productRepository = new PdoProductRepository($this->pdo);
    }

    public function index(): string
    {
        $stmt = $this->pdo->query('SELECT * FROM products ORDER BY id DESC LIMIT 50');
        $products = $stmt->fetchAll();

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/products.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>Products</h1>';
        }
        return (string)ob_get_clean();
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function apiList(array $queryParams = []): array
    {
        $search = isset($queryParams['search']) ? trim((string)$queryParams['search']) : null;
        $categorySlug = isset($queryParams['category']) ? trim((string)$queryParams['category']) : null;
        $limit = isset($queryParams['limit']) ? max(1, min(100, (int)$queryParams['limit'])) : 20;
        $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
        $offset = ($page - 1) * $limit;

        if ($search !== null && $search !== '') {
            $products = $this->productRepository->search(['search' => $search], $limit, $offset);
            $total = $this->productRepository->count(['search' => $search]);
        } elseif ($categorySlug !== null && $categorySlug !== '') {
            $products = $this->productRepository->search(['category_slug' => $categorySlug], $limit, $offset);
            $total = $this->productRepository->count(['category_slug' => $categorySlug]);
        } else {
            $total = $this->productRepository->count([]);
            $products = $this->productRepository->search([], $limit, $offset);
        }

        return [
            'data' => array_map(fn(Product $p) => $p->toArray(), $products),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apiShow(int $id): ?array
    {
        $product = $this->productRepository->findById($id);
        return $product?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apiShowBySku(string $sku): ?array
    {
        $product = $this->productRepository->findBySku($sku);
        return $product?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apiCreate(array $data): array
    {
        if (!isset($data['name']) || trim((string)$data['name']) === '') {
            throw new InvalidArgumentException('Validation failed: product name is required');
        }
        if (!isset($data['price']) || !is_numeric($data['price'])) {
            throw new InvalidArgumentException('Validation failed: price must be numeric');
        }

        $name = trim((string)$data['name']);
        $sku = isset($data['sku']) && trim((string)$data['sku']) !== ''
            ? trim((string)$data['sku'])
            : 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
        $slug = isset($data['slug']) && trim((string)$data['slug']) !== ''
            ? trim((string)$data['slug'])
            : strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'product');

        $priceCents = (int)$data['price'];
        $currency = isset($data['currency']) ? (string)$data['currency'] : 'USD';
        $stockQty = isset($data['stock_quantity']) ? (int)$data['stock_quantity'] : 0;
        $type = isset($data['type']) ? ProductType::from((string)$data['type']) : ProductType::Simple;
        $stockStatus = StockStatus::fromQuantity($stockQty, false);

        $product = new Product(
            id: null,
            name: $name,
            slug: $slug,
            sku: $sku,
            type: $type,
            price: new Money($priceCents, $currency),
            salePrice: null,
            stockQuantity: $stockQty,
            stockStatus: $stockStatus,
            manageStock: true,
            weight: 0.0,
            description: (string)($data['description'] ?? ''),
            shortDescription: (string)($data['short_description'] ?? ''),
            isActive: true,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );

        $savedProduct = $this->productRepository->save($product);
        return $savedProduct->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apiUpdate(int $id, array $data): array
    {
        $product = $this->productRepository->findById($id);
        if ($product === null) {
            throw new \WinCart\Catalog\Exceptions\ProductNotFoundException("Product #{$id} not found");
        }

        $fields = [];
        $params = [':id' => $id, ':now' => date('Y-m-d H:i:s')];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params[':name'] = (string)$data['name'];
        }
        if (isset($data['price']) && is_numeric($data['price'])) {
            $fields[] = 'price = :price';
            $params[':price'] = (int)$data['price'];
        }
        if (isset($data['stock_quantity']) && is_numeric($data['stock_quantity'])) {
            $fields[] = 'stock_quantity = :stock_quantity';
            $params[':stock_quantity'] = (int)$data['stock_quantity'];
            $fields[] = 'stock_status = :stock_status';
            $params[':stock_status'] = (int)$data['stock_quantity'] > 0 ? 'in_stock' : 'out_of_stock';
        }

        if (!empty($fields)) {
            $sql = 'UPDATE products SET ' . implode(', ', $fields) . ', updated_at = :now WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        $updatedProduct = $this->productRepository->findById($id);
        return $updatedProduct?->toArray() ?? [];
    }

    public function apiDelete(int $id): bool
    {
        return $this->productRepository->delete($id);
    }
}
