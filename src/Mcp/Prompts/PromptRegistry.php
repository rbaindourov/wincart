<?php

declare(strict_types=1);

namespace WinCart\Mcp\Prompts;

use InvalidArgumentException;

final class PromptRegistry
{
    /** @var array<string, PromptInterface> */
    private array $prompts = [];

    public function register(PromptInterface $prompt): void
    {
        $this->prompts[$prompt->getName()] = $prompt;
    }

    public function get(string $name): ?PromptInterface
    {
        return $this->prompts[$name] ?? null;
    }

    /**
     * @return array<int, array{name: string, description: string, arguments: array<int, array<string, mixed>>}>
     */
    public function listPrompts(): array
    {
        $list = [];
        foreach ($this->prompts as $p) {
            $list[] = [
                'name' => $p->getName(),
                'description' => $p->getDescription(),
                'arguments' => $p->getArguments(),
            ];
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function getPrompt(string $name, array $arguments = []): PromptResult
    {
        $prompt = $this->get($name);
        if ($prompt === null) {
            throw new InvalidArgumentException("Prompt not found: {$name}");
        }

        return $prompt->get($arguments);
    }
}
