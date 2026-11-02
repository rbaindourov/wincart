<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;

final class SearchProductsTool implements ToolInterface
{
    public function __construct(private ProductRepositoryInterface $productRepo)
    {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Search and filter store products by query string, category ID, stock availability, with pagination.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keyword to search across product name, SKU, or description',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Filter products belonging to a specific category ID',
                ],
                'in_stock_only' => [
                    'type' => 'boolean',
                    'description' => 'If true, returns only products currently in stock',
                    'default' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of products to return (1-100)',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset',
                    'default' => 0,
                    'minimum' => 0,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $query = isset($arguments['query']) ? trim((string)$arguments['query']) : null;
        $categoryId = isset($arguments['category_id']) ? (int)$arguments['category_id'] : null;
        $inStockOnly = !empty($arguments['in_stock_only']);
        $limit = isset($arguments['limit']) ? max(1, min(100, (int)$arguments['limit'])) : 20;
        $offset = isset($arguments['offset']) ? max(0, (int)$arguments['offset']) : 0;

        $criteria = [];
        if ($query !== null && $query !== '') {
            $criteria['search'] = $query;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $criteria['category_id'] = $categoryId;
        }
        if ($inStockOnly) {
            $criteria['stock_status'] = 'in_stock';
        }

        $products = $this->productRepo->search($criteria, $limit, $offset);
        $total = $this->productRepo->count($criteria);

        $payload = [
            'query' => $query ?? '',
            'category_id' => $categoryId,
            'in_stock_only' => $inStockOnly,
            'total' => $total,
            'count' => count($products),
            'limit' => $limit,
            'offset' => $offset,
            'products' => array_map(fn(Product $p) => $p->toArray(), $products),
        ];

        return ToolResult::json($payload);
    }
}
