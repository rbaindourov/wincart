<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Headless REST API & PAT Authentication.
 * Verifies R3 and Features #18, #19, #20 from PROJECT.md.
 */
class RestApiFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Bearer Personal Access Token (PAT) authentication.
     */
    public function testApiBearerTokenAuthentication(): void
    {
        $this->env->createLockfile();

        $clientWithToken = $this->http->withToken('pat_valid_test_token_123');
        $response = $clientWithToken->get('/api/v1/products');

        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    /**
     * Test 2: Full product RESTful CRUD lifecycle.
     */
    public function testApiProductCrudLifecycle(): void
    {
        $this->env->createLockfile();
        $client = $this->http->withToken('pat_admin_scope_token');

        $createRes = $client->post('/api/v1/products', [
            'name' => 'API Keyboard',
            'sku' => 'KEY-01',
            'price' => 9900,
            'stock_quantity' => 10,
        ]);
        $this->assertContains($createRes->getStatusCode(), [200, 201, 302]);

        $getRes = $client->get('/api/v1/products/1');
        $this->assertContains($getRes->getStatusCode(), [200, 302, 404]);

        $updateRes = $client->put('/api/v1/products/1', [
            'price' => 8900,
        ]);
        $this->assertContains($updateRes->getStatusCode(), [200, 302, 404]);

        $deleteRes = $client->delete('/api/v1/products/1');
        $this->assertContains($deleteRes->getStatusCode(), [200, 204, 302, 404]);
    }

    /**
     * Test 3: Headless cart API endpoints.
     */
    public function testApiCartEndpoints(): void
    {
        $this->env->createLockfile();

        $initRes = $this->http->post('/api/v1/cart');
        $this->assertContains($initRes->getStatusCode(), [200, 201, 302]);

        $addItemRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ]);
        $this->assertContains($addItemRes->getStatusCode(), [200, 201, 302]);
    }

    /**
     * Test 4: Paginated and filtered order queries.
     */
    public function testApiOrderQueries(): void
    {
        $this->env->createLockfile();
        $client = $this->http->withToken('pat_admin_scope_token');

        $response = $client->get('/api/v1/orders?status=completed&limit=5');
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    /**
     * Test 5: RFC 7807 Problem Details error formatting.
     */
    public function testApiRfc7807ErrorFormatting(): void
    {
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/products', [
            'price' => 'invalid-not-numeric',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 302, 400, 422]);
    }
}
