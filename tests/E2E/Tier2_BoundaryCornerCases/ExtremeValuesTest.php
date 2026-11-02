<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Extreme Values, Text Payloads & Precision.
 * Verifies edge cases #11-#15 from PROJECT.md and test specifications.
 */
class ExtremeValuesTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: 10,000-character address input handles cleanly without buffer overflow or crash.
     */
    public function testExtremeTenThousandCharacterAddressInput(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $largeText = str_repeat('A', 10000);
        $address = new Address(
            firstName: 'Long',
            lastName: 'User',
            address1: $largeText,
            city: 'Metropolis',
            state: 'NY',
            postalCode: '10001',
            country: 'US'
        );

        $this->assertSame(10000, strlen($address->address1));

        $response = $this->http->post('/api/v1/checkout', [
            'shipping_address' => $address->toArray(),
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Test 2: Multi-byte UTF-8 international strings, CJK, and 4-byte emojis.
     */
    public function testExtremeMultibyteUtf8InternationalInputs(): void
    {
        $intlText = '高級レザー財布 👛 — Кожаный кошелек — René Müller — مرحبا';

        $address = new Address(
            firstName: 'René',
            lastName: 'Müller',
            address1: $intlText,
            city: 'Токио',
            state: '東京',
            postalCode: '100-0001',
            country: 'JP'
        );

        $this->assertSame($intlText, $address->address1);
        $array = $address->toArray();
        $this->assertSame($intlText, $array['address_1']);
    }

    /**
     * Test 3: Large order with 50+ distinct line items calculates totals with zero IEEE-754 drift.
     */
    public function testExtremeOrderWithFiftyPlusLineItems(): void
    {
        $runningTotal = new Money(0, 'USD');
        $itemCount = 55;
        $unitPrice = new Money(1999, 'USD'); // $19.99

        for ($i = 0; $i < $itemCount; $i++) {
            $runningTotal = $runningTotal->add($unitPrice);
        }

        $expectedCents = 55 * 1999;
        $this->assertSame($expectedCents, $runningTotal->getAmount());
        $this->assertSame(109945, $runningTotal->getAmount());
        $this->assertSame('$1,099.45', $runningTotal->format());
    }

    /**
     * Test 4: Extreme monetary values ($20,000,000.00+) without integer overflow.
     */
    public function testExtremeLargeMonetaryAmounts(): void
    {
        // $20,000,000.00 in minor units = 2,000,000,000 cents
        $largeAmount = new Money(2000000000, 'USD');
        $this->assertSame(2000000000, $largeAmount->getAmount());
        $this->assertSame('$20,000,000.00', $largeAmount->format());

        $doubled = $largeAmount->multiply(2);
        $this->assertSame(4000000000, $doubled->getAmount());
        $this->assertSame('$40,000,000.00', $doubled->format());
    }

    /**
     * Test 5: Out-of-bounds pagination limits and safe clamping.
     */
    public function testExtremeHighCardinalityPaginationBounds(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/products?limit=5000&page=999999');
        $this->assertSame(200, $response->getStatusCode());
    }
}
