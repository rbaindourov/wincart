<?php

declare(strict_types=1);

namespace WinCart\Catalog\Models;

use JsonSerializable;

final class Attribute implements JsonSerializable
{
    /**
     * @param array<AttributeValue> $values
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $slug,
        private array $values = []
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

    /**
     * @return array<AttributeValue>
     */
    public function getValues(): array
    {
        return $this->values;
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
            'values' => array_map(fn(AttributeValue $v) => $v->toArray(), $this->values),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
