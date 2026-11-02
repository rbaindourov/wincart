<?php

declare(strict_types=1);

namespace WinCart\Payment;

use WinCart\Common\Money;

interface PaymentGatewayInterface
{
    public function getId(): string;

    public function getName(): string;

    public function supports(string $method): bool;

    public function processPayment(PaymentRequest $request): PaymentResult;

    public function refund(string $transactionId, Money $amount, string $reason): PaymentResult;
}
