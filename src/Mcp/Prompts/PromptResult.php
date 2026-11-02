<?php

declare(strict_types=1);

namespace WinCart\Mcp\Prompts;

final class PromptResult
{
    /**
     * @param array<int, array{role: string, content: array{type: string, text: string}}> $messages
     */
    public function __construct(
        private string $description,
        private array $messages
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<int, array{role: string, content: array{type: string, text: string}}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return array{description: string, messages: array<int, array<string, mixed>>}
     */
    public function toResponseArray(): array
    {
        return [
            'description' => $this->description,
            'messages' => $this->messages,
        ];
    }
}
