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
        $this->requireHttp();
        $this->env->createLockfile();

        $clientWithToken = $this->http->withToken('pat_valid_test_token_123');
        $response = $clientWithToken->get('/api/v1/products');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 2: Full product RESTful CRUD lifecycle.
     */
    public function testApiProductCrudLifecycle(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();
        $client = $this->http->withToken('pat_admin_scope_token');

        $createRes = $client->post('/api/v1/products', [
            'name' => 'API Keyboard',
            'sku' => 'KEY-01',
            'price' => 9900,
            'stock_quantity' => 10,
        ]);
        $this->assertSame(201, $createRes->getStatusCode());

        $getRes = $client->get('/api/v1/products/1');
        $this->assertSame(200, $getRes->getStatusCode());

        $updateRes = $client->put('/api/v1/products/1', [
            'price' => 8900,
        ]);
        $this->assertSame(200, $updateRes->getStatusCode());

        $deleteRes = $client->delete('/api/v1/products/1');
        $this->assertSame(204, $deleteRes->getStatusCode());
    }

    /**
     * Test 3: Headless cart API endpoints.
     */
    public function testApiCartEndpoints(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $initRes = $this->http->post('/api/v1/cart');
        $this->assertSame(201, $initRes->getStatusCode());

        $addItemRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addItemRes->getStatusCode());
    }

    /**
     * Test 4: Paginated and filtered order queries.
     */
    public function testApiOrderQueries(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();
        $client = $this->http->withToken('pat_admin_scope_token');

        $response = $client->get('/api/v1/orders?status=completed&limit=5');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 5: RFC 7807 Problem Details error formatting.
     */
    public function testApiRfc7807ErrorFormatting(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->withToken('pat_admin_scope_token')->post('/api/v1/products', [
            'price' => 'invalid-not-numeric',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Unprocessable Entity');
        $json = $response->getJson();
        $this->assertArrayHasKey('type', $json);
        $this->assertArrayHasKey('title', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('detail', $json);
        $this->assertSame(422, (int)$json['status']);
        $this->assertStringContainsStringIgnoringCase('price', (string)$json['detail']);
    }
}
