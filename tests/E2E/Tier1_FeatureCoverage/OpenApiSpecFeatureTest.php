<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — OpenAPI 3.0.3 Specification & Schema Validation.
 * Verifies R3, Feature #21 from PROJECT.md and Milestone 4 acceptance contracts.
 */
class OpenApiSpecFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: OpenAPI schema is served with HTTP 200 at both /openapi.json and /api/v1/openapi.json.
     */
    public function testOpenApiSpecServesAtStandardPaths(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // 1. Root /openapi.json
        $rootRes = $this->http->get('/openapi.json');
        $this->assertSame(200, $rootRes->getStatusCode());
        $this->assertStringContainsString('application/json', $rootRes->getHeaderLine('Content-Type'));

        // 2. Versioned /api/v1/openapi.json
        $v1Res = $this->http->get('/api/v1/openapi.json');
        $this->assertSame(200, $v1Res->getStatusCode());
        $this->assertStringContainsString('application/json', $v1Res->getHeaderLine('Content-Type'));

        $this->assertSame($rootRes->getBody(), $v1Res->getBody());
    }

    /**
     * Test 2: OpenAPI document conforms to version 3.0.3 structure.
     */
    public function testOpenApiSpecConformsToOpenApi30(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $res = $this->http->get('/api/v1/openapi.json');
        $schema = $this->assertJsonResponse($res, 200);

        $this->assertArrayHasKey('openapi', $schema);
        $this->assertSame('3.0.3', $schema['openapi']);

        $this->assertArrayHasKey('info', $schema);
        $this->assertArrayHasKey('title', $schema['info']);
        $this->assertArrayHasKey('version', $schema['info']);
        $this->assertArrayHasKey('description', $schema['info']);

        $this->assertArrayHasKey('servers', $schema);
        $this->assertIsArray($schema['servers']);
        $this->assertNotEmpty($schema['servers']);
    }

    /**
     * Test 3: OpenAPI document defines paths for all core API resources.
     */
    public function testOpenApiSpecDocumentsAllCoreV1Paths(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $res = $this->http->get('/api/v1/openapi.json');
        $schema = $this->assertJsonResponse($res, 200);

        $this->assertArrayHasKey('paths', $schema);
        $paths = $schema['paths'];

        $requiredPaths = [
            '/api/v1/products',
            '/api/v1/products/{id_or_slug}',
            '/api/v1/categories',
            '/api/v1/categories/{id_or_slug}',
            '/api/v1/cart',
            '/api/v1/cart/items',
            '/api/v1/cart/items/{id}',
            '/api/v1/cart/coupons',
            '/api/v1/checkout',
            '/api/v1/orders',
            '/api/v1/orders/{id}',
            '/api/v1/orders/{id}/status',
            '/api/v1/orders/{id}/refund',
            '/api/v1/admin/metrics',
        ];

        foreach ($requiredPaths as $path) {
            $this->assertArrayHasKey($path, $paths, "OpenAPI spec must document path {$path}");
        }
    }

    /**
     * Test 4: OpenAPI document defines Bearer PAT securityScheme with granular scopes.
     */
    public function testOpenApiSpecDefinesSecuritySchemesAndScopes(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $res = $this->http->get('/api/v1/openapi.json');
        $schema = $this->assertJsonResponse($res, 200);

        $this->assertArrayHasKey('components', $schema);
        $this->assertArrayHasKey('securitySchemes', $schema['components']);
        $this->assertArrayHasKey('bearerAuth', $schema['components']['securitySchemes']);

        $bearer = $schema['components']['securitySchemes']['bearerAuth'];
        $this->assertSame('http', $bearer['type']);
        $this->assertSame('bearer', $bearer['scheme']);
        $this->assertSame('PAT', $bearer['bearerFormat']);
        $this->assertStringContainsString('products:read', (string)$bearer['description']);
        $this->assertStringContainsString('products:write', (string)$bearer['description']);
        $this->assertStringContainsString('orders:read', (string)$bearer['description']);
        $this->assertStringContainsString('orders:write', (string)$bearer['description']);
        $this->assertStringContainsString('admin:read', (string)$bearer['description']);
        $this->assertStringContainsString('admin:write', (string)$bearer['description']);
    }

    /**
     * Test 5: OpenAPI document defines RFC 7807 ProblemDetails schema.
     */
    public function testOpenApiSpecDefinesRfc7807ProblemDetailsSchema(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $res = $this->http->get('/api/v1/openapi.json');
        $schema = $this->assertJsonResponse($res, 200);

        $this->assertArrayHasKey('components', $schema);
        $this->assertArrayHasKey('schemas', $schema['components']);
        $this->assertArrayHasKey('ProblemDetails', $schema['components']['schemas']);

        $problem = $schema['components']['schemas']['ProblemDetails'];
        $this->assertArrayHasKey('properties', $problem);
        $props = $problem['properties'];
        $this->assertArrayHasKey('type', $props);
        $this->assertArrayHasKey('title', $props);
        $this->assertArrayHasKey('status', $props);
        $this->assertArrayHasKey('detail', $props);
    }
}
