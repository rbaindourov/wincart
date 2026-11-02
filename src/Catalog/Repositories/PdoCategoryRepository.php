<?php

declare(strict_types=1);

namespace WinCart\Catalog\Repositories;

use DateTimeImmutable;
use PDO;
use WinCart\Catalog\Models\Category;

final class PdoCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateCategory($row) : null;
    }

    public function findBySlug(string $slug): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateCategory($row) : null;
    }

    /**
     * @return array<Category>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, name ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrateCategory($row), $rows);
    }

    /**
     * @return array<Category>
     */
    public function findTree(): array
    {
        $all = $this->findAll();
        $byParent = [];

        foreach ($all as $category) {
            $parentId = $category->getParentId() ?? 0;
            $byParent[$parentId][] = $category;
        }

        $visited = [];
        $buildTree = function (int $parentId) use (&$buildTree, $byParent, &$visited): array {
            $tree = [];
            foreach ($byParent[$parentId] ?? [] as $category) {
                $catId = $category->getId() ?? 0;
                if (isset($visited[$catId])) {
                    continue;
                }
                $visited[$catId] = true;
                $category->setChildren($buildTree($catId));
                $tree[] = $category;
            }
            return $tree;
        };

        return $buildTree(0);
    }

    /**
     * @return array<int>
     */
    public function findSubtreeIds(int $categoryId): array
    {
        $all = $this->findAll();
        $byParent = [];

        foreach ($all as $category) {
            $parentId = $category->getParentId() ?? 0;
            $byParent[$parentId][] = $category->getId() ?? 0;
        }

        $ids = [$categoryId];
        $visited = [$categoryId => true];
        $collect = function (int $id) use (&$collect, $byParent, &$ids, &$visited): void {
            foreach ($byParent[$id] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $ids[] = $childId;
                $collect($childId);
            }
        };

        $collect($categoryId);
        return array_values(array_unique($ids));
    }

    public function save(Category $category): Category
    {
        $now = date('Y-m-d H:i:s');

        if ($category->getId() === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO categories (
                    parent_id, name, slug, description, image_url, sort_order, is_active, created_at, updated_at
                ) VALUES (
                    :parent_id, :name, :slug, :description, :image_url, :sort_order, :is_active,
                    :created_at, :updated_at
                )'
            );
            $stmt->execute([
                'parent_id' => $category->getParentId(),
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
                'description' => $category->getDescription(),
                'image_url' => $category->getImageUrl(),
                'sort_order' => $category->getSortOrder(),
                'is_active' => $category->isActive() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            return $this->findById($newId) ?? $category;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE categories
             SET parent_id = :parent_id, name = :name, slug = :slug, description = :description,
                 image_url = :image_url, sort_order = :sort_order, is_active = :is_active, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $category->getId(),
            'parent_id' => $category->getParentId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'description' => $category->getDescription(),
            'image_url' => $category->getImageUrl(),
            'sort_order' => $category->getSortOrder(),
            'is_active' => $category->isActive() ? 1 : 0,
            'updated_at' => $now,
        ]);

        return $this->findById($category->getId()) ?? $category;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCategory(array $row): Category
    {
        return new Category(
            id: (int)$row['id'],
            name: (string)$row['name'],
            slug: (string)$row['slug'],
            description: isset($row['description']) ? (string)$row['description'] : null,
            parentId: isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            sortOrder: (int)($row['sort_order'] ?? 0),
            imageUrl: isset($row['image_url']) ? (string)$row['image_url'] : null,
            isActive: (bool)($row['is_active'] ?? 1),
            children: [],
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string)$row['updated_at']) : null
        );
    }
}
