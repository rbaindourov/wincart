<?php

declare(strict_types=1);

namespace WinCart\Payment;

use JsonSerializable;

final readonly class PaymentResult implements JsonSerializable
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public bool $successful,
        public ?string $transactionId = null,
        public ?string $errorMessage = null,
        public array $rawResponse = []
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function success(string $transactionId, array $raw = []): self
    {
        return new self(true, $transactionId, null, $raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function failure(string $errorMessage, array $raw = []): self
    {
        return new self(false, null, $errorMessage, $raw);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->successful,
            'transaction_id' => $this->transactionId,
            'error_message' => $this->errorMessage,
            'raw' => $this->rawResponse,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
