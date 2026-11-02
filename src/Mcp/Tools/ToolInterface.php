<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<string, mixed> JSON Schema object
     */
    public function getInputSchema(): array;

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): ToolResult;
}
