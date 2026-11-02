<?php

declare(strict_types=1);

namespace WinCart\Mcp\Resources;

use InvalidArgumentException;

final class ResourceRegistry
{
    /** @var array<string, ResourceInterface> */
    private array $resources = [];

    public function register(ResourceInterface $resource): void
    {
        $this->resources[$resource->getUri()] = $resource;
    }

    public function get(string $uri): ?ResourceInterface
    {
        return $this->resources[$uri] ?? null;
    }

    /**
     * @return array<int, array{uri: string, name: string, description: string, mimeType: string}>
     */
    public function listResources(): array
    {
        $list = [];
        foreach ($this->resources as $res) {
            $list[] = [
                'uri' => $res->getUri(),
                'name' => $res->getName(),
                'description' => $res->getDescription(),
                'mimeType' => $res->getMimeType(),
            ];
        }
        return $list;
    }

    public function read(string $uri): ResourceContent
    {
        $res = $this->get($uri);
        if ($res === null) {
            throw new InvalidArgumentException("Resource not found: {$uri}");
        }

        return $res->read();
    }
}
