<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Catalog\Repositories\ProductRepositoryInterface;

final class GetProductTool implements ToolInterface
{
    public function __construct(private ProductRepositoryInterface $productRepo)
    {
    }

    public function getName(): string
    {
        return 'get_product';
    }

    public function getDescription(): string
    {
        return 'Retrieve detailed product entity including variations, pricing, stock, '
            . 'and categories by ID, slug, or SKU.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Unique integer product ID',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL-friendly product slug (e.g. "ergonomic-chair")',
                ],
                'sku' => [
                    'type' => 'string',
                    'description' => 'Product SKU (e.g. "PROD-SKU-99")',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $product = null;

        if (isset($arguments['id']) && (int)$arguments['id'] > 0) {
            $product = $this->productRepo->findById((int)$arguments['id']);
        } elseif (isset($arguments['slug']) && trim((string)$arguments['slug']) !== '') {
            $product = $this->productRepo->findBySlug(trim((string)$arguments['slug']));
        } elseif (isset($arguments['sku']) && trim((string)$arguments['sku']) !== '') {
            $product = $this->productRepo->findBySku(trim((string)$arguments['sku']));
        } else {
            return ToolResult::text(
                "Error: Must provide at least one identifier ('id', 'slug', or 'sku')",
                isError: true
            );
        }

        if ($product === null) {
            return ToolResult::text("Error: Product not found", isError: true);
        }

        return ToolResult::json($product->toArray());
    }
}
