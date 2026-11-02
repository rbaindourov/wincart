<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Catalog\Repositories\ProductRepositoryInterface;

final class DeleteProductTool implements ToolInterface
{
    public function __construct(private ProductRepositoryInterface $productRepo)
    {
    }

    public function getName(): string
    {
        return 'delete_product';
    }

    public function getDescription(): string
    {
        return 'Delete a product from the catalog by its unique ID.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Unique product ID to delete',
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

        $existing = $this->productRepo->findById($id);
        if ($existing === null) {
            return ToolResult::text("Error: Product #{$id} not found", isError: true);
        }

        $deleted = $this->productRepo->delete($id);

        return ToolResult::json([
            'success' => $deleted,
            'id' => $id,
            'message' => "Product #{$id} deleted successfully",
        ]);
    }
}
