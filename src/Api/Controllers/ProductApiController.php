<?php

declare(strict_types=1);

namespace WinCart\Api\Controllers;

use PDO;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Http\ProblemDetails;
use WinCart\Tests\E2E\Support\HttpResponse;

final class ProductApiController
{
    private ProductRepositoryInterface $productRepository;

    public function __construct(private PDO $pdo)
    {
        $this->productRepository = new PdoProductRepository($this->pdo);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function list(array $queryParams = []): array
    {
        $criteria = [];
        $search = $queryParams['query'] ?? $queryParams['search'] ?? null;
        if ($search !== null && trim((string)$search) !== '') {
            $criteria['search'] = trim((string)$search);
        }

        if (isset($queryParams['category_id']) && is_numeric($queryParams['category_id'])) {
            $criteria['category_id'] = (int)$queryParams['category_id'];
        } elseif (isset($queryParams['category']) || isset($queryParams['category_slug'])) {
            $criteria['category_slug'] = (string)($queryParams['category'] ?? $queryParams['category_slug']);
        }

        if (isset($queryParams['in_stock'])) {
            $inStock = filter_var($queryParams['in_stock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($inStock === true || $queryParams['in_stock'] === '1' || $queryParams['in_stock'] === 1) {
                $criteria['stock_status'] = 'in_stock';
            }
        }

        $limit = isset($queryParams['per_page'])
            ? max(1, min(100, (int)$queryParams['per_page']))
            : (isset($queryParams['limit']) ? max(1, min(100, (int)$queryParams['limit'])) : 20);

        $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
        $offset = isset($queryParams['offset']) ? max(0, (int)$queryParams['offset']) : (($page - 1) * $limit);

        $products = $this->productRepository->search($criteria, $limit, $offset);
        $total = $this->productRepository->count($criteria);

        return [
            'data' => array_map(fn(Product $p) => $p->toArray(), $products),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'per_page' => $limit,
        ];
    }

    public function show(string $idOrSlug): HttpResponse
    {
        $product = null;
        if (ctype_digit($idOrSlug)) {
            $product = $this->productRepository->findById((int)$idOrSlug);
        } else {
            $product = $this->productRepository->findBySlug($idOrSlug)
                ?? $this->productRepository->findBySku($idOrSlug);
        }

        if ($product === null) {
            return ProblemDetails::create(404, 'Not Found', "Product '{$idOrSlug}' not found");
        }

        return $this->json(200, $product->toArray());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): HttpResponse
    {
        if (!isset($data['name']) || trim((string)$data['name']) === '') {
            return ProblemDetails::create(422, 'Unprocessable Entity', 'Validation failed: product name is required');
        }
        if (!isset($data['price']) || !is_numeric($data['price'])) {
            return ProblemDetails::create(422, 'Unprocessable Entity', 'Validation failed: price must be numeric');
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
            compareAtPrice: null,
            costPrice: null,
            description: (string)($data['description'] ?? ''),
            shortDescription: (string)($data['short_description'] ?? ''),
            stockQuantity: $stockQty,
            stockStatus: $stockStatus,
            manageStock: true,
            backordersAllowed: false,
            requiresShipping: true,
            weight: 0.0,
            isActive: true,
            categoryIds: [],
            variations: [],
            images: [],
            attributes: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );

        $saved = $this->productRepository->save($product);
        return $this->json(201, $saved->toArray());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): HttpResponse
    {
        $product = $this->productRepository->findById($id);
        if ($product === null) {
            return ProblemDetails::create(404, 'Not Found', "Product #{$id} not found");
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
        if (isset($data['description'])) {
            $fields[] = 'description = :description';
            $params[':description'] = (string)$data['description'];
        }

        if (!empty($fields)) {
            $sql = 'UPDATE products SET ' . implode(', ', $fields) . ', updated_at = :now WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        $updated = $this->productRepository->findById($id);
        return $this->json(200, $updated?->toArray() ?? []);
    }

    public function delete(int $id): HttpResponse
    {
        $this->productRepository->delete($id);
        return new HttpResponse(204, '', []);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $code, array $data): HttpResponse
    {
        return new HttpResponse(
            $code,
            (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }
}
