<?php

declare(strict_types=1);

namespace WinCart\Payment;

use WinCart\Common\Address;
use WinCart\Common\Money;

final readonly class PaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Money $amount,
        public string $token,
        public string $orderReference,
        public ?Address $billingAddress = null,
        public array $metadata = []
    ) {
    }
}
