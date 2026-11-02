<?php

declare(strict_types=1);

namespace WinCart\Payment;

use InvalidArgumentException;

final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->getId()] = $gateway;
    }

    public function get(string $method): PaymentGatewayInterface
    {
        $normalized = strtolower(trim($method));
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($normalized)) {
                return $gateway;
            }
        }
        throw new InvalidArgumentException("Unsupported payment method: '{$method}'");
    }

    /**
     * @return array<string, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }
}
