<?php

declare(strict_types=1);

namespace WinCart\Catalog\Models;

use JsonSerializable;

final class AttributeValue implements JsonSerializable
{
    public function __construct(
        private ?int $id,
        private int $attributeId,
        private string $value,
        private string $slug
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttributeId(): int
    {
        return $this->attributeId;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attribute_id' => $this->attributeId,
            'value' => $this->value,
            'slug' => $this->slug,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
