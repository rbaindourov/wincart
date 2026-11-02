<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

final class ToolResult
{
    /**
     * @param array<int, array{type: string, text: string}> $content
     */
    public function __construct(
        private array $content,
        private bool $isError = false
    ) {
    }

    public static function text(string $text, bool $isError = false): self
    {
        return new self([
            ['type' => 'text', 'text' => $text],
        ], $isError);
    }

    public static function json(mixed $data, bool $isError = false): self
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new self([
            ['type' => 'text', 'text' => (string)$json],
        ], $isError);
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    public function toResponseArray(): array
    {
        return [
            'content' => $this->content,
            'isError' => $this->isError,
        ];
    }
}
