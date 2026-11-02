<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WinCart\Common\Address;
use WinCart\Common\Exceptions\CurrencyMismatchException;
use WinCart\Common\Exceptions\WinCartException;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

/**
 * Baseline Smoke Test Suite for Milestone 1.
 *
 * Verifies PSR-4 autoloading, domain type contracts, math precision, and enum state machines.
 */
final class ScaffoldingSmokeTest extends TestCase
{
    /**
     * Assert Composer PSR-4 autoloader correctly resolves all Common domain classes and enums.
     */
    public function testAutoloaderResolvesCoreDomainTypes(): void
    {
        $this->assertTrue(class_exists(Money::class), 'Money class must be resolved by PSR-4 autoloader');
        $this->assertTrue(class_exists(Address::class), 'Address class must be resolved by PSR-4 autoloader');
        $this->assertTrue(enum_exists(OrderStatus::class), 'OrderStatus enum must be resolved by PSR-4 autoloader');
        $this->assertTrue(enum_exists(ProductType::class), 'ProductType enum must be resolved by PSR-4 autoloader');
        $this->assertTrue(enum_exists(StockStatus::class), 'StockStatus enum must be resolved by PSR-4 autoloader');
        $this->assertTrue(class_exists(CurrencyMismatchException::class), 'CurrencyMismatchException must be resolved');
        $this->assertTrue(class_exists(WinCartException::class), 'WinCartException must be resolved');

        // Verify readonly immutability
        $this->assertTrue((new ReflectionClass(Money::class))->isReadOnly(), 'Money must be a readonly class');
        $this->assertTrue((new ReflectionClass(Address::class))->isReadOnly(), 'Address must be a readonly class');
    }

    /**
     * Assert Money instantiation, factories, and zero float representation drift.
     */
    public function testMoneyInstantiationAndFloatPrecision(): void
    {
        $m1 = new Money(1999, 'USD');
        $this->assertSame(1999, $m1->getAmount());
        $this->assertSame('USD', $m1->getCurrency());
        $this->assertSame(19.99, $m1->toFloat());

        $m2 = Money::fromCents(1999);
        $this->assertTrue($m1->equals($m2));

        $m3 = Money::fromFloat(19.99);
        $this->assertTrue($m1->equals($m3));

        // Classic IEEE-754 precision bug test: 0.1 + 0.2 != 0.3 in IEEE-754
        $floatSum = 0.1 + 0.2;
        $mFloat = Money::fromFloat($floatSum);
        $this->assertSame(30, $mFloat->getAmount(), 'fromFloat must round float arithmetic to exact minor cents');
        $this->assertSame('$0.30', $mFloat->format());

        // Zero factory
        $zero = Money::zero('USD');
        $this->assertTrue($zero->isZero());
        $this->assertFalse($zero->isPositive());
    }

    /**
     * Assert Money rejects negative amounts.
     */
    public function testMoneyRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');
        new Money(-500);
    }

    /**
     * Assert Money arithmetic: addition, subtraction, multiplication, and immutability.
     */
    public function testMoneyArithmeticOperations(): void
    {
        $ten = Money::fromCents(1000);
        $twoFifty = Money::fromCents(250);

        // Addition
        $sum = $ten->add($twoFifty);
        $this->assertSame(1250, $sum->getAmount());
        $this->assertSame(1000, $ten->getAmount(), 'Original instance must remain unchanged (immutable)');

        // Subtraction
        $diff = $ten->subtract($twoFifty);
        $this->assertSame(750, $diff->getAmount());

        // Subtraction below zero throws
        $this->expectException(InvalidArgumentException::class);
        $twoFifty->subtract($ten);
    }

    /**
     * Assert Money multiplication with integers and floating point tax/discount percentages.
     */
    public function testMoneyMultiplicationWithRounding(): void
    {
        $price = Money::fromCents(1999); // $19.99

        // Quantity multiplication
        $threeItems = $price->multiply(3);
        $this->assertSame(5997, $threeItems->getAmount()); // $59.97

        // Tax rate calculation (e.g. 8.25% tax on $10.00: 1000 * 1.0825 = 1082.5 -> rounds to 1083 cents)
        $tenDollars = Money::fromCents(1000);
        $withTax = $tenDollars->multiply(1.0825);
        $this->assertSame(1083, $withTax->getAmount());
        $this->assertSame('$10.83', $withTax->format());
    }

    /**
     * Assert CurrencyMismatchException when combining disparate currencies.
     */
    public function testMoneyCurrencyMismatchEnforcement(): void
    {
        $usd = Money::fromCents(1000, 'USD');
        $eur = Money::fromCents(1000, 'EUR');

        $this->expectException(CurrencyMismatchException::class);
        $usd->add($eur);
    }

    /**
     * Assert Money comparisons: equals, greaterThan, lessThan.
     */
    public function testMoneyComparisons(): void
    {
        $low = Money::fromCents(500);
        $high = Money::fromCents(1500);
        $equal = Money::fromCents(500);

        $this->assertTrue($low->equals($equal));
        $this->assertFalse($low->equals($high));
        $this->assertTrue($high->greaterThan($low));
        $this->assertTrue($high->greaterThanOrEqual($low));
        $this->assertTrue($low->lessThan($high));
        $this->assertTrue($low->lessThanOrEqual($equal));
    }

    /**
     * Assert Money formatting and JSON serialization.
     */
    public function testMoneyFormattingAndJson(): void
    {
        $m1 = Money::fromCents(1234567, 'USD');
        $this->assertSame('$12,345.67', $m1->format());
        $this->assertSame('$12,345.67', (string) $m1);

        $mEur = Money::fromCents(999, 'EUR');
        $this->assertSame('€9.99', $mEur->format());

        $json = json_encode($m1);
        $this->assertIsString($json);
        $data = json_decode($json, true);
        $this->assertSame(1234567, $data['amount']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame(12345.67, $data['float']);
        $this->assertSame('$12,345.67', $data['formatted']);
    }

    /**
     * Assert Address validation, formatting, and round-trip serialization.
     */
    public function testAddressValidationAndFormatting(): void
    {
        $addr = new Address(
            firstName: 'John',
            lastName: 'Doe',
            address1: '123 Market St',
            city: 'San Francisco',
            state: 'CA',
            postalCode: '94105',
            country: 'US',
            address2: 'Suite 400',
            phone: '415-555-0199',
            company: 'Acme Logistics'
        );

        $this->assertSame('John Doe', $addr->getFullName());
        $this->assertSame('Acme Logistics', $addr->company);

        // Formatted address inspection
        $formatted = $addr->getFormattedAddress();
        $this->assertStringContainsString("Acme Logistics\nJohn Doe", $formatted);
        $this->assertStringContainsString('123 Market St', $formatted);
        $this->assertStringContainsString('Suite 400', $formatted);
        $this->assertStringContainsString('San Francisco, CA 94105', $formatted);
        $this->assertStringContainsString('Tel: 415-555-0199', $formatted);

        // Serialization round-trip
        $array = $addr->toArray();
        $restored = Address::fromArray($array);
        $this->assertSame($addr->firstName, $restored->firstName);
        $this->assertSame($addr->postalCode, $restored->postalCode);
        $this->assertSame($addr->getFormattedAddress(), $restored->getFormattedAddress());

        // Rejection of empty required fields
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('First name cannot be empty');
        new Address(
            firstName: '   ',
            lastName: 'Doe',
            address1: '123 St',
            city: 'City',
            state: 'ST',
            postalCode: '12345'
        );
    }

    /**
     * Assert OrderStatus enum backing values and finite state machine transitions.
     */
    public function testOrderStatusEnumAndStateMachine(): void
    {
        // Assert backing values match contract
        $this->assertSame('pending', OrderStatus::Pending->value);
        $this->assertSame('processing', OrderStatus::Processing->value);
        $this->assertSame('completed', OrderStatus::Completed->value);
        $this->assertSame('cancelled', OrderStatus::Cancelled->value);
        $this->assertSame('refunded', OrderStatus::Refunded->value);

        // FSM transitions: Pending
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Processing));
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Completed));

        // FSM transitions: Processing
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Refunded));

        // FSM transitions: Completed
        $this->assertTrue(OrderStatus::Completed->canTransitionTo(OrderStatus::Refunded));
        $this->assertFalse(OrderStatus::Completed->canTransitionTo(OrderStatus::Pending));

        // Terminal states
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Processing));
        $this->assertFalse(OrderStatus::Refunded->canTransitionTo(OrderStatus::Completed));
    }

    /**
     * Assert ProductType enum contracts and shipping requirements.
     */
    public function testProductTypeEnumContract(): void
    {
        $this->assertSame('simple', ProductType::Simple->value);
        $this->assertSame('variable', ProductType::Variable->value);
        $this->assertSame('downloadable', ProductType::Downloadable->value);

        $this->assertTrue(ProductType::Simple->isSimple());
        $this->assertTrue(ProductType::Variable->isVariable());
        $this->assertTrue(ProductType::Downloadable->isDownloadable());

        $this->assertTrue(ProductType::Simple->requiresShipping());
        $this->assertTrue(ProductType::Variable->requiresShipping());
        $this->assertFalse(
            ProductType::Downloadable->requiresShipping(),
            'Downloadable products must not require shipping'
        );
    }

    /**
     * Assert StockStatus enum contracts and quantity resolution helpers.
     */
    public function testStockStatusEnumContract(): void
    {
        $this->assertSame('instock', StockStatus::InStock->value);
        $this->assertSame('outofstock', StockStatus::OutOfStock->value);
        $this->assertSame('onbackorder', StockStatus::OnBackorder->value);

        $this->assertTrue(StockStatus::InStock->isAvailable());
        $this->assertTrue(StockStatus::OnBackorder->isAvailable());
        $this->assertFalse(StockStatus::OutOfStock->isAvailable());

        // fromQuantity factory
        $this->assertSame(StockStatus::InStock, StockStatus::fromQuantity(5));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromQuantity(0, false));
        $this->assertSame(StockStatus::OnBackorder, StockStatus::fromQuantity(0, true));
    }
}
