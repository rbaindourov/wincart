<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Value object representing a JSON-RPC 2.0 response from the Model Context Protocol (MCP) server.
 */
class McpResponse
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        private array $rawPayload
    ) {
    }

    public function getId(): int|string|null
    {
        return $this->rawPayload['id'] ?? null;
    }

    public function isSuccess(): bool
    {
        return isset($this->rawPayload['result']) && !isset($this->rawPayload['error']);
    }

    public function isError(): bool
    {
        return isset($this->rawPayload['error']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getResult(): array
    {
        $res = $this->rawPayload['result'] ?? [];
        return is_array($res) ? $res : ['value' => $res];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getError(): ?array
    {
        $err = $this->rawPayload['error'] ?? null;
        return is_array($err) ? $err : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContent(): array
    {
        $result = $this->getResult();
        if (isset($result['content']) && is_array($result['content'])) {
            return $result['content'];
        }
        return [];
    }

    public function getTextContent(): string
    {
        $content = $this->getContent();
        $textParts = [];
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $textParts[] = (string)$item['text'];
            }
        }
        return implode("\n", $textParts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->rawPayload;
    }
}
