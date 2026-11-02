<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use Throwable;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(): array
    {
        $result = [];
        foreach ($this->tools as $tool) {
            $result[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments = []): ToolResult
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return ToolResult::text("Tool not found: {$name}", isError: true);
        }

        try {
            return $tool->execute($arguments);
        } catch (Throwable $e) {
            return ToolResult::text("Tool execution error ({$name}): " . $e->getMessage(), isError: true);
        }
    }
}
