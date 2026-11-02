<?php

declare(strict_types=1);

namespace WinCart\Mcp\Resources;

interface ResourceInterface
{
    public function getUri(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function getMimeType(): string;

    public function read(): ResourceContent;
}
