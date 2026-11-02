<?php

declare(strict_types=1);

namespace WinCart\Catalog\Models;

use DateTimeImmutable;
use JsonSerializable;

final class Category implements JsonSerializable
{
    /**
     * @param array<Category> $children
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $slug,
        private ?string $description = null,
        private ?int $parentId = null,
        private int $sortOrder = 0,
        private ?string $imageUrl = null,
        private bool $isActive = true,
        private array $children = [],
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<Category>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param array<Category> $children
     */
    public function setChildren(array $children): void
    {
        $this->children = $children;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_id' => $this->parentId,
            'sort_order' => $this->sortOrder,
            'image_url' => $this->imageUrl,
            'is_active' => $this->isActive,
            'children' => array_map(fn(Category $c) => $c->toArray(), $this->children),
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
