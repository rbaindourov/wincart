<?php

declare(strict_types=1);

namespace WinCart\Mcp\Prompts;

interface PromptInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    public function getArguments(): array;

    /**
     * @param array<string, mixed> $arguments
     */
    public function get(array $arguments): PromptResult;
}
