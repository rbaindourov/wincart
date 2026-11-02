<?php

declare(strict_types=1);

namespace WinCart\Payment;

use WinCart\Common\Money;

final class MockPaymentGateway implements PaymentGatewayInterface
{
    public function getId(): string
    {
        return 'mock_gateway';
    }

    public function getName(): string
    {
        return 'Mock Payment Gateway';
    }

    public function supports(string $method): bool
    {
        return in_array(strtolower(trim($method)), ['mock_gateway', 'mock', 'mock_payment_gateway', 'test'], true);
    }

    public function processPayment(PaymentRequest $request): PaymentResult
    {
        $token = strtolower(trim($request->token));

        if ($token === 'tok_mock_decline' || $token === 'tok_decline') {
            return PaymentResult::failure('Card declined: insufficient funds', ['token' => $token]);
        }

        if ($token === 'tok_mock_error' || $token === 'tok_error') {
            return PaymentResult::failure('Payment gateway communication error', ['token' => $token]);
        }

        $txnId = 'txn_mock_' . bin2hex(random_bytes(6));
        return PaymentResult::success($txnId, [
            'token' => $token,
            'amount' => $request->amount->getAmount(),
            'currency' => $request->amount->getCurrency(),
            'order_reference' => $request->orderReference,
            'gateway' => 'mock',
        ]);
    }

    public function refund(string $transactionId, Money $amount, string $reason): PaymentResult
    {
        $refundId = 'ref_mock_' . bin2hex(random_bytes(6));
        return PaymentResult::success($refundId, [
            'orig_txn' => $transactionId,
            'amount' => $amount->getAmount(),
            'reason' => $reason,
        ]);
    }
}
