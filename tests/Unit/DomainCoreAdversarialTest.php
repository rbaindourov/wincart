<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;
use ValueError;
use WinCart\Common\Address;
use WinCart\Common\Exceptions\CurrencyMismatchException;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

/**
 * Adversarial Stress & Edge Case Test Suite for Milestone 1 Domain Core.
 *
 * Authored by Adversarial Challenger 1 to stress-test Money precision,
 * OrderStatus 5x5 state transitions, Address validation, and Enums.
 */
final class DomainCoreAdversarialTest extends TestCase
{
    // =========================================================================
    // 1. Adversarial Money Math & Precision
    // =========================================================================

    /**
     * Test floating-point rounding edge cases (sub-cents, half-up, small decimals).
     */
    public function testMoneyFloatingPointRoundingEdgeCases(): void
    {
        // $0.005 -> half-up rounds to 1 cent ($0.01)
        $halfCent = Money::fromFloat(0.005);
        $this->assertSame(1, $halfCent->getAmount());
        $this->assertSame('$0.01', $halfCent->format());

        // $0.0049 -> rounds down to 0 cents ($0.00)
        $subHalfCent = Money::fromFloat(0.0049);
        $this->assertSame(0, $subHalfCent->getAmount());
        $this->assertSame('$0.00', $subHalfCent->format());
        $this->assertTrue($subHalfCent->isZero());

        // $0.0051 -> rounds up to 1 cent
        $aboveHalfCent = Money::fromFloat(0.0051);
        $this->assertSame(1, $aboveHalfCent->getAmount());

        // 0.994 -> 99 cents
        $subNinetyNine = Money::fromFloat(0.994);
        $this->assertSame(99, $subNinetyNine->getAmount());

        // 0.995 -> 100 cents ($1.00)
        $ninetyNineHalf = Money::fromFloat(0.995);
        $this->assertSame(100, $ninetyNineHalf->getAmount());
        $this->assertSame('$1.00', $ninetyNineHalf->format());

        // Large precision decimal with rounding
        $largeDecimal = Money::fromFloat(12345.678);
        $this->assertSame(1234568, $largeDecimal->getAmount());
        $this->assertSame('$12,345.68', $largeDecimal->format());

        $largeDecimalDown = Money::fromFloat(12345.672);
        $this->assertSame(1234567, $largeDecimalDown->getAmount());
        $this->assertSame('$12,345.67', $largeDecimalDown->format());

        // Sub-cent negative float rounding to zero does not trigger negative exception
        $tinyNegative = Money::fromFloat(-0.0049);
        $this->assertSame(0, $tinyNegative->getAmount());
        $this->assertTrue($tinyNegative->isZero());
    }

    /**
     * Test Money zero amount behaviors.
     */
    public function testMoneyZeroAmountBehaviors(): void
    {
        $zeroUsd = Money::zero('USD');
        $this->assertSame(0, $zeroUsd->getAmount());
        $this->assertTrue($zeroUsd->isZero());
        $this->assertFalse($zeroUsd->isPositive());
        $this->assertSame(0.0, $zeroUsd->toFloat());
        $this->assertSame('$0.00', $zeroUsd->format());

        $zeroEur = Money::fromCents(0, 'EUR');
        $this->assertSame(0, $zeroEur->getAmount());
        $this->assertSame('EUR', $zeroEur->getCurrency());
        $this->assertSame('€0.00', $zeroEur->format());

        $zeroFromFloat = Money::fromFloat(0.0);
        $this->assertTrue($zeroUsd->equals($zeroFromFloat));
    }

    /**
     * Test strict rejection of negative amounts across all constructors and factories.
     */
    public function testMoneyStrictRejectionOfNegativeAmounts(): void
    {
        // Direct constructor
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');
        new Money(-1);
    }

    public function testMoneyFromCentsRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');
        Money::fromCents(-100);
    }

    public function testMoneyFromFloatRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');
        Money::fromFloat(-0.01);
    }

    public function testMoneyFromFloatRejectsHalfCentNegative(): void
    {
        // -0.005 * 100 = -0.5 -> round(-0.5) = -1 -> triggers negative check
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');
        Money::fromFloat(-0.005);
    }

    /**
     * Test ISO-4217 currency code validation and whitespace trimming.
     */
    public function testMoneyCurrencyValidationAndNormalization(): void
    {
        // Trimming and uppercasing
        $m1 = Money::fromCents(500, '  usd  ');
        $this->assertSame('USD', $m1->getCurrency());

        $m2 = Money::fromCents(500, 'eur');
        $this->assertSame('EUR', $m2->getCurrency());

        // Rejection of invalid currency length
        $invalidCurrencies = ['US', 'USDD', '', '   ', '1', '12345'];
        foreach ($invalidCurrencies as $invalid) {
            try {
                Money::fromCents(100, $invalid);
                $this->fail("Expected InvalidArgumentException for invalid currency '{$invalid}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid ISO-4217 currency code', $e->getMessage());
            }
        }
    }

    /**
     * Test arithmetic operations: addition, subtraction, zero identities, and immutability.
     */
    public function testMoneyArithmeticExhaustive(): void
    {
        $zero = Money::zero('USD');
        $five = Money::fromCents(500, 'USD');
        $ten = Money::fromCents(1000, 'USD');

        // Add identity: A + 0 = A
        $addZero = $five->add($zero);
        $this->assertSame(500, $addZero->getAmount());
        $this->assertTrue($five->equals($addZero));

        // Sub identity: A - 0 = A
        $subZero = $five->subtract($zero);
        $this->assertSame(500, $subZero->getAmount());
        $this->assertTrue($five->equals($subZero));

        // Sub to zero: A - A = 0
        $subSelf = $five->subtract($five);
        $this->assertSame(0, $subSelf->getAmount());
        $this->assertTrue($subSelf->isZero());

        // Normal addition
        $sum = $five->add($ten);
        $this->assertSame(1500, $sum->getAmount());

        // Normal subtraction
        $diff = $ten->subtract($five);
        $this->assertSame(500, $diff->getAmount());

        // Subtraction resulting in negative balance strictly throws
        try {
            $five->subtract($ten);
            $this->fail('Expected InvalidArgumentException when subtracting larger amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('negative balances not permitted', $e->getMessage());
        }

        // Immutability: original objects must remain unmodified
        $this->assertSame(500, $five->getAmount());
        $this->assertSame(1000, $ten->getAmount());
    }

    /**
     * Test multiplication with rounding modes and scalar validation.
     */
    public function testMoneyMultiplicationWithVariousRoundingModes(): void
    {
        $base = Money::fromCents(100, 'USD'); // $1.00

        // Zero multiplier
        $multipliedZero = $base->multiply(0);
        $this->assertTrue($multipliedZero->isZero());

        // Negative multiplier strictly throws
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier cannot be negative');
        $base->multiply(-2);
    }

    public function testMoneyMultiplicationRoundingModesBehavior(): void
    {
        $base = Money::fromCents(100, 'USD');

        // 100 * 0.025 = 2.5
        // PHP_ROUND_HALF_UP rounds halves away from zero: 2.5 -> 3
        $up = $base->multiply(0.025, PHP_ROUND_HALF_UP);
        $this->assertSame(3, $up->getAmount());

        // PHP_ROUND_HALF_DOWN rounds halves towards zero: 2.5 -> 2
        $down = $base->multiply(0.025, PHP_ROUND_HALF_DOWN);
        $this->assertSame(2, $down->getAmount());

        // PHP_ROUND_HALF_EVEN: 2.5 -> 2 (2 is even)
        $even = $base->multiply(0.025, PHP_ROUND_HALF_EVEN);
        $this->assertSame(2, $even->getAmount());

        // PHP_ROUND_HALF_ODD: 2.5 -> 3 (3 is odd)
        $odd = $base->multiply(0.025, PHP_ROUND_HALF_ODD);
        $this->assertSame(3, $odd->getAmount());

        // For 3.5: use 10 * 0.35 = exactly 3.5 in IEEE-754
        $base10 = Money::fromCents(10, 'USD');
        // PHP_ROUND_HALF_EVEN: 3.5 -> 4 (4 is even)
        $even35 = $base10->multiply(0.35, PHP_ROUND_HALF_EVEN);
        $this->assertSame(4, $even35->getAmount());

        // PHP_ROUND_HALF_ODD: 3.5 -> 3 (3 is odd)
        $odd35 = $base10->multiply(0.35, PHP_ROUND_HALF_ODD);
        $this->assertSame(3, $odd35->getAmount());
    }

    /**
     * Test currency mismatch enforcement across all binary arithmetic and comparison operations.
     */
    public function testMoneyCurrencyMismatchEnforcementExhaustive(): void
    {
        $usd = Money::fromCents(1000, 'USD');
        $eur = Money::fromCents(1000, 'EUR');

        // add() throws CurrencyMismatchException
        try {
            $usd->add($eur);
            $this->fail('add() should throw CurrencyMismatchException on mismatched currency');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('USD and EUR', $e->getMessage());
        }

        // subtract() throws CurrencyMismatchException
        try {
            $usd->subtract($eur);
            $this->fail('subtract() should throw CurrencyMismatchException on mismatched currency');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('USD and EUR', $e->getMessage());
        }

        // greaterThan() throws CurrencyMismatchException
        try {
            $usd->greaterThan($eur);
            $this->fail('greaterThan() should throw CurrencyMismatchException');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('Currency mismatch', $e->getMessage());
        }

        // greaterThanOrEqual() throws CurrencyMismatchException
        try {
            $usd->greaterThanOrEqual($eur);
            $this->fail('greaterThanOrEqual() should throw CurrencyMismatchException');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('Currency mismatch', $e->getMessage());
        }

        // lessThan() throws CurrencyMismatchException
        try {
            $usd->lessThan($eur);
            $this->fail('lessThan() should throw CurrencyMismatchException');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('Currency mismatch', $e->getMessage());
        }

        // lessThanOrEqual() throws CurrencyMismatchException
        try {
            $usd->lessThanOrEqual($eur);
            $this->fail('lessThanOrEqual() should throw CurrencyMismatchException');
        } catch (CurrencyMismatchException $e) {
            $this->assertStringContainsString('Currency mismatch', $e->getMessage());
        }

        // equals() must NOT throw CurrencyMismatchException, but return false
        $this->assertFalse($usd->equals($eur));
    }

    /**
     * Test string formatting with thousands separators, single-digit cents, and custom currencies.
     */
    public function testMoneyFormattingAndSymbolsExhaustive(): void
    {
        // USD symbol and formatting
        $this->assertSame('$0.01', Money::fromCents(1, 'USD')->format());
        $this->assertSame('$0.05', Money::fromCents(5, 'USD')->format());
        $this->assertSame('$0.09', Money::fromCents(9, 'USD')->format());
        $this->assertSame('$0.10', Money::fromCents(10, 'USD')->format());
        $this->assertSame('$0.50', Money::fromCents(50, 'USD')->format());
        $this->assertSame('$1,000.00', Money::fromCents(100000, 'USD')->format());
        $this->assertSame('$1,000,000.00', Money::fromCents(100000000, 'USD')->format());
        $this->assertSame('$1,234,567,890.12', Money::fromCents(123456789012, 'USD')->format());

        // EUR symbol
        $this->assertSame('€1,500.50', Money::fromCents(150050, 'EUR')->format());

        // GBP symbol
        $this->assertSame('£49.99', Money::fromCents(4999, 'GBP')->format());

        // CAD symbol
        $this->assertSame('CA$75.00', Money::fromCents(7500, 'CAD')->format());

        // AUD symbol
        $this->assertSame('AU$89.95', Money::fromCents(8995, 'AUD')->format());

        // Unknown / fallback currency: uses code + space
        $this->assertSame('CHF 120.00', Money::fromCents(12000, 'CHF')->format());
        $this->assertSame('JPY 5,000.00', Money::fromCents(500000, 'JPY')->format());
    }

    // =========================================================================
    // 2. OrderStatus State Machine Exhaustive 5x5 Challenge
    // =========================================================================

    /**
     * Test all 25 possible state transition pairs across Pending, Processing, Completed,
     * Cancelled, and Refunded against the authoritative domain state machine contract.
     */
    public function testOrderStatusExhaustive25TransitionMatrix(): void
    {
        $statuses = [
            OrderStatus::Pending,
            OrderStatus::Processing,
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ];

        // Authoritative 5x5 transition truth table
        $expectedMatrix = [
            'pending' => [
                'pending' => true,      // self no-op
                'processing' => true,   // payment captured -> processing
                'completed' => false,  // illegal: cannot skip processing/fulfillment
                'cancelled' => true,   // customer/store cancelled before payment
                'refunded' => false,   // illegal: cannot refund uncaptured payment
            ],
            'processing' => [
                'pending' => false,    // illegal: cannot revert back to pending payment
                'processing' => true,  // self no-op
                'completed' => true,   // fulfillment complete -> completed
                'cancelled' => true,   // cancelled during processing
                'refunded' => true,    // refunded during processing (e.g. out of stock)
            ],
            'completed' => [
                'pending' => false,    // illegal: cannot revert completed order to pending
                'processing' => false, // illegal: cannot un-fulfill completed order
                'completed' => true,   // self no-op
                'cancelled' => false,  // illegal: fulfilled order must be refunded, not cancelled
                'refunded' => true,    // customer return/refund
            ],
            'cancelled' => [
                'pending' => false,    // terminal
                'processing' => false, // terminal
                'completed' => false,  // terminal
                'cancelled' => true,   // self no-op
                'refunded' => false,   // terminal
            ],
            'refunded' => [
                'pending' => false,    // terminal
                'processing' => false, // terminal
                'completed' => false,  // terminal
                'cancelled' => false,  // terminal
                'refunded' => true,    // self no-op
            ],
        ];

        $testedPairs = 0;
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                $expected = $expectedMatrix[$from->value][$to->value];
                $actual = $from->canTransitionTo($to);

                $this->assertSame(
                    $expected,
                    $actual,
                    sprintf(
                        'FSM transition %s -> %s must evaluate to %s, got %s',
                        $from->name,
                        $to->name,
                        $expected ? 'TRUE' : 'FALSE',
                        $actual ? 'TRUE' : 'FALSE'
                    )
                );
                $testedPairs++;
            }
        }

        $this->assertSame(25, $testedPairs, 'Exhaustive transition test must cover exactly 25 pairs');
    }

    /**
     * Test OrderStatus terminal status property across all 5 states.
     */
    public function testOrderStatusTerminalStates(): void
    {
        $this->assertFalse(OrderStatus::Pending->isTerminal());
        $this->assertFalse(OrderStatus::Processing->isTerminal());
        $this->assertFalse(OrderStatus::Completed->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());
    }

    /**
     * Test OrderStatus labels and CSS badge classes for presentation completeness.
     */
    public function testOrderStatusLabelsAndBadgeClasses(): void
    {
        $cases = [
            [OrderStatus::Pending, 'Pending Payment', 'badge-warning'],
            [OrderStatus::Processing, 'Processing', 'badge-info'],
            [OrderStatus::Completed, 'Completed', 'badge-success'],
            [OrderStatus::Cancelled, 'Cancelled', 'badge-danger'],
            [OrderStatus::Refunded, 'Refunded', 'badge-secondary'],
        ];

        foreach ($cases as [$status, $expectedLabel, $expectedBadge]) {
            $this->assertSame($expectedLabel, $status->label());
            $this->assertSame($expectedBadge, $status->badgeClass());
        }
    }

    // =========================================================================
    // 3. Address and Enums Adversarial & Boundary Testing
    // =========================================================================

    /**
     * Test Address strict rejection of missing and whitespace-only required fields.
     */
    public function testAddressRequiredFieldsRejection(): void
    {
        $validBase = [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'address1' => '100 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postalCode' => '78701',
            'country' => 'US',
        ];

        $requiredFields = ['firstName', 'lastName', 'address1', 'city', 'state', 'postalCode', 'country'];

        foreach ($requiredFields as $field) {
            // Test with empty string
            $params = $validBase;
            $params[$field] = '';
            try {
                new Address(...$params);
                $this->fail("Expected InvalidArgumentException for empty {$field}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot be empty', $e->getMessage());
            }

            // Test with whitespace string
            $params[$field] = "   \t\n   ";
            try {
                new Address(...$params);
                $this->fail("Expected InvalidArgumentException for whitespace-only {$field}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot be empty', $e->getMessage());
            }
        }
    }

    /**
     * Test Address handling of extreme input lengths (10,000 chars) and special characters.
     */
    public function testAddressExtremeLengthsAndSpecialCharacters(): void
    {
        $longString = str_repeat('A', 10000);
        $utf8String = 'René François de l’Hôpital — 東京都 渋谷区 — 🏠📦';

        $addr = new Address(
            firstName: $utf8String,
            lastName: 'Tester',
            address1: $longString,
            city: 'München',
            state: 'Bayern',
            postalCode: '80331',
            country: 'DE',
            company: 'Siemens AG & Co. <script>alert("xss")</script>'
        );

        $this->assertSame($utf8String, $addr->firstName);
        $this->assertSame($longString, $addr->address1);
        $this->assertSame('DE', $addr->country);

        // Verification of formatted string contains UTF-8 and special chars lossless
        $formatted = $addr->getFormattedAddress();
        $this->assertStringContainsString('René François de l’Hôpital', $formatted);
        $this->assertStringContainsString('München, Bayern 80331', $formatted);
        $this->assertStringContainsString('Siemens AG & Co.', $formatted);

        // JSON serialization
        $json = json_encode($addr, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertStringContainsString('München', $json);
    }

    /**
     * Test Address optional fields normalization (whitespace/empty string -> null).
     */
    public function testAddressOptionalFieldsNormalization(): void
    {
        $addr = new Address(
            firstName: 'Alice',
            lastName: 'Wonderland',
            address1: '42 Rabbit Hole Rd',
            city: 'Oxford',
            state: 'OX',
            postalCode: 'OX1',
            country: 'gb', // lowercase country
            address2: '   ',
            phone: '',
            company: " \t "
        );

        // Optional fields normalized to null
        $this->assertNull($addr->address2);
        $this->assertNull($addr->phone);
        $this->assertNull($addr->company);

        // Country normalized to uppercase
        $this->assertSame('GB', $addr->country);

        // Formatted address does not contain empty lines or tel prefix
        $formatted = $addr->getFormattedAddress();
        $this->assertStringNotContainsString('Tel:', $formatted);
    }

    /**
     * Test Address::fromArray with key permutations (camelCase vs snake_case, zip, postcode).
     */
    public function testAddressFromArrayKeyPermutations(): void
    {
        // Snake case payload
        $snakeCase = [
            'first_name' => 'Bob',
            'last_name' => 'Builder',
            'address_1' => '1 Can-Do Way',
            'address_2' => 'Suite 2B',
            'city' => 'Fixham',
            'state' => 'FH',
            'postal_code' => '12345',
            'country' => 'US',
            'phone' => '555-0199',
            'company' => 'Bob Construction',
        ];

        $addr1 = Address::fromArray($snakeCase);
        $this->assertSame('Bob', $addr1->firstName);
        $this->assertSame('Builder', $addr1->lastName);
        $this->assertSame('1 Can-Do Way', $addr1->address1);
        $this->assertSame('Suite 2B', $addr1->address2);
        $this->assertSame('12345', $addr1->postalCode);

        // CamelCase with alternative postal keys ('zip' or 'postcode')
        $camelCase = [
            'firstName' => 'Carol',
            'lastName' => 'Danvers',
            'address1' => '77 Air Base Rd',
            'city' => 'Boston',
            'state' => 'MA',
            'zip' => '02101',
            'country' => 'US',
        ];

        $addr2 = Address::fromArray($camelCase);
        $this->assertSame('Carol', $addr2->firstName);
        $this->assertSame('02101', $addr2->postalCode);

        // Integer values in payload are safely cast to string
        $intPayload = [
            'first_name' => 'David',
            'last_name' => 'Miller',
            'address_1' => '500 Highway 1',
            'city' => 'Dallas',
            'state' => 'TX',
            'postal_code' => 75001,
            'phone' => 2145551234,
        ];

        $addr3 = Address::fromArray($intPayload);
        $this->assertSame('75001', $addr3->postalCode);
        $this->assertSame('2145551234', $addr3->phone);
    }

    /**
     * Test ProductType backing values, shipping rules, and invalid values.
     */
    public function testProductTypeEnumsAdversarial(): void
    {
        $this->assertSame(ProductType::Simple, ProductType::from('simple'));
        $this->assertSame(ProductType::Variable, ProductType::from('variable'));
        $this->assertSame(ProductType::Downloadable, ProductType::from('downloadable'));

        $this->assertTrue(ProductType::Simple->requiresShipping());
        $this->assertTrue(ProductType::Variable->requiresShipping());
        $this->assertFalse(ProductType::Downloadable->requiresShipping());

        // Rejection of invalid backing values
        $this->expectException(ValueError::class);
        ProductType::from('invalid_type');
    }

    /**
     * Test StockStatus boundary conditions and fromQuantity logic across zero and negatives.
     */
    public function testStockStatusBoundaryCalculations(): void
    {
        // Positive quantity -> InStock
        $this->assertSame(StockStatus::InStock, StockStatus::fromQuantity(100));
        $this->assertSame(StockStatus::InStock, StockStatus::fromQuantity(1));
        $this->assertSame(StockStatus::InStock, StockStatus::fromQuantity(1, true));

        // Zero quantity without backorders -> OutOfStock
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromQuantity(0, false));

        // Zero quantity with backorders -> OnBackorder
        $this->assertSame(StockStatus::OnBackorder, StockStatus::fromQuantity(0, true));

        // Negative quantity (e.g. oversold) without backorders -> OutOfStock
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromQuantity(-5, false));

        // Negative quantity with backorders -> OnBackorder
        $this->assertSame(StockStatus::OnBackorder, StockStatus::fromQuantity(-5, true));

        // Availability check
        $this->assertTrue(StockStatus::InStock->isAvailable());
        $this->assertTrue(StockStatus::OnBackorder->isAvailable());
        $this->assertFalse(StockStatus::OutOfStock->isAvailable());
    }

    /**
     * Test Money behavior on integer overflow beyond PHP_INT_MAX.
     */
    public function testMoneyIntegerOverflowBehavior(): void
    {
        $max = Money::fromCents(PHP_INT_MAX, 'USD');
        $one = Money::fromCents(1, 'USD');

        // Adding 1 to PHP_INT_MAX in PHP produces a float, which triggers TypeError on __construct(int $amount)
        $this->expectException(TypeError::class);
        $max->add($one);
    }

    /**
     * Test Money formatting with PHP_INT_MAX cents.
     */
    public function testMoneyLargeNumberFormatting(): void
    {
        $max = Money::fromCents(PHP_INT_MAX, 'USD');
        $formatted = $max->format();
        $this->assertStringStartsWith('$', $formatted);
        $this->assertStringContainsString(',', $formatted);
    }

    /**
     * Test Address Stringable contract and full JSON round-trip deserialization.
     */
    public function testAddressRoundTripAndStringable(): void
    {
        $addr = new Address(
            firstName: 'Sarah',
            lastName: 'Connor',
            address1: '404 Resistance Blvd',
            city: 'Los Angeles',
            state: 'CA',
            postalCode: '90001',
            country: 'US',
            address2: 'Bldg 3',
            phone: '213-555-0100',
            company: 'Cyberdyne Systems'
        );

        // Stringable interface
        $this->assertSame($addr->getFormattedAddress(), (string) $addr);

        // JSON decode and re-hydrate
        $json = json_encode($addr, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $rehydrated = Address::fromArray($decoded);

        $this->assertSame($addr->firstName, $rehydrated->firstName);
        $this->assertSame($addr->lastName, $rehydrated->lastName);
        $this->assertSame($addr->company, $rehydrated->company);
        $this->assertSame($addr->address1, $rehydrated->address1);
        $this->assertSame($addr->address2, $rehydrated->address2);
        $this->assertSame($addr->city, $rehydrated->city);
        $this->assertSame($addr->state, $rehydrated->state);
        $this->assertSame($addr->postalCode, $rehydrated->postalCode);
        $this->assertSame($addr->country, $rehydrated->country);
        $this->assertSame($addr->phone, $rehydrated->phone);
        $this->assertSame($addr->getFormattedAddress(), $rehydrated->getFormattedAddress());
    }

    /**
     * Test Enum tryFrom returns null for unknown values.
     */
    public function testEnumsTryFromReturnsNullForUnknown(): void
    {
        $this->assertNull(OrderStatus::tryFrom('non_existent'));
        $this->assertNull(ProductType::tryFrom('non_existent'));
        $this->assertNull(StockStatus::tryFrom('non_existent'));
    }
}
