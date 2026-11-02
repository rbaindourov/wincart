<?php

declare(strict_types=1);

namespace WinCart\Mcp\Resources;

final class ResourceContent
{
    public function __construct(
        private string $uri,
        private string $mimeType,
        private string $text
    ) {
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    public function toResponseArray(): array
    {
        return [
            'uri' => $this->uri,
            'mimeType' => $this->mimeType,
            'text' => $this->text,
        ];
    }
}
