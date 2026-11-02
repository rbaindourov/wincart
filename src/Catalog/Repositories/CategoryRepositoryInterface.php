<?php

declare(strict_types=1);

namespace WinCart\Catalog\Repositories;

use WinCart\Catalog\Models\Category;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;

    public function findBySlug(string $slug): ?Category;

    /**
     * @return array<Category>
     */
    public function findAll(): array;

    /**
     * Returns nested hierarchical category tree.
     *
     * @return array<Category>
     */
    public function findTree(): array;

    /**
     * Returns all descendant category IDs (self + children + grandchildren).
     *
     * @return array<int>
     */
    public function findSubtreeIds(int $categoryId): array;

    public function save(Category $category): Category;

    public function delete(int $id): bool;
}
