<?php

declare(strict_types=1);

namespace WinCart\Common;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;
use WinCart\Common\Exceptions\CurrencyMismatchException;

/**
 * Immutable Money value object representing monetary amounts in minor units (cents).
 *
 * Prevents IEEE-754 floating point arithmetic drift and guarantees currency isolation.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public int $amount;
    public string $currency;

    /**
     * @param int $amount Amount in minor units (e.g. cents). Must be non-negative.
     * @param string $currency ISO-4217 three-letter currency code (e.g. 'USD').
     */
    public function __construct(
        int $amount,
        string $currency = 'USD'
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException("Money amount cannot be negative: {$amount}");
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if (strlen($normalizedCurrency) !== 3) {
            throw new InvalidArgumentException("Invalid ISO-4217 currency code: '{$currency}'");
        }

        $this->amount = $amount;
        $this->currency = $normalizedCurrency;
    }

    /**
     * Factory: create instance from minor units (cents).
     */
    public static function fromCents(int $cents, string $currency = 'USD'): self
    {
        return new self($cents, $currency);
    }

    /**
     * Factory: create instance from float decimal (e.g. 19.99 -> 1999 cents).
     *
     * Uses standard half-up rounding to neutralize floating point representation drift.
     */
    public static function fromFloat(float $amount, string $currency = 'USD'): self
    {
        return new self((int) round($amount * 100), $currency);
    }

    /**
     * Factory: create zero monetary amount.
     */
    public static function zero(string $currency = 'USD'): self
    {
        return new self(0, $currency);
    }

    /**
     * Factory: convenience constructor for USD cents.
     */
    public static function usd(int $cents): self
    {
        return new self($cents, 'USD');
    }

    /**
     * Accessor: amount in minor units.
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * Accessor: ISO currency code.
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Convert minor units to floating point representation (e.g. 1999 -> 19.99).
     */
    public function toFloat(): float
    {
        return $this->amount / 100.0;
    }

    /**
     * Add another Money instance of the same currency.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another Money instance of the same currency.
     *
     * Throws InvalidArgumentException if the subtraction would result in a negative balance.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        $diff = $this->amount - $other->amount;

        if ($diff < 0) {
            throw new InvalidArgumentException(
                "Cannot subtract {$other->format()} from {$this->format()}: negative balances not permitted"
            );
        }

        return new self($diff, $this->currency);
    }

    /**
     * Multiply money by a scalar quantity or decimal rate (e.g. tax, discount).
     */
    public function multiply(int|float $multiplier, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException("Multiplier cannot be negative: {$multiplier}");
        }

        $result = (int) round($this->amount * $multiplier, 0, $roundingMode);
        return new self($result, $this->currency);
    }

    /**
     * Equality comparison.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Strictly greater than comparison.
     */
    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount > $other->amount;
    }

    /**
     * Greater than or equal comparison.
     */
    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount >= $other->amount;
    }

    /**
     * Strictly less than comparison.
     */
    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount < $other->amount;
    }

    /**
     * Less than or equal comparison.
     */
    public function lessThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount <= $other->amount;
    }

    /**
     * Check if amount is exactly zero.
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Check if amount is positive (> 0).
     */
    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Format monetary value into a human-readable string with currency symbol.
     */
    public function format(): string
    {
        $symbol = match ($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'AU$',
            default => $this->currency . ' ',
        };

        return $symbol . number_format($this->toFloat(), 2);
    }

    /**
     * Stringable representation.
     */
    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * JSON serialization format.
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'float' => $this->toFloat(),
            'formatted' => $this->format(),
        ];
    }

    /**
     * Guard: assert operand currencies are identical.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException(
                sprintf(
                    'Currency mismatch: operation requires identical currencies, received %s and %s',
                    $this->currency,
                    $other->currency
                )
            );
        }
    }
}
