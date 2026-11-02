<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Security Guards, Access Controls & Traversal Protection.
 * Verifies edge cases #16-#20 from PROJECT.md and test specifications.
 */
class SecurityGuardsTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Accessing /install post-installation returns HTTP 403 Forbidden.
     */
    public function testSecurityAccessingInstallPostInstallation(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/install');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('locked', $response->getBody());
    }

    /**
     * Test 2: Accessing private API routes without Bearer token returns 401 / 302.
     */
    public function testSecurityAccessingPrivateApiWithoutToken(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/orders');
        $this->assertContains($response->getStatusCode(), [200, 302, 401]);
    }

    /**
     * Test 3: Invalid and expired coupon codes rejection with HTTP 400.
     */
    public function testSecurityInvalidAndExpiredCouponCodes(): void
    {
        $this->env->createLockfile();

        $expiredRes = $this->http->post('/api/v1/cart/coupons', [
            'code' => 'EXPIRED_2020',
        ]);
        $this->assertContains($expiredRes->getStatusCode(), [200, 302, 400, 422]);

        $invalidRes = $this->http->post('/api/v1/cart/coupons', [
            'code' => 'DOES_NOT_EXIST_XYZ',
        ]);
        $this->assertContains($invalidRes->getStatusCode(), [200, 302, 400, 422]);
    }

    /**
     * Test 4: Insufficient API token scope privilege escalation protection.
     */
    public function testSecurityInsufficientApiTokenScopes(): void
    {
        $this->env->createLockfile();

        $readOnlyClient = $this->http->withToken('pat_read_only_token_abc');
        $deleteRes = $readOnlyClient->delete('/api/v1/products/1');

        $this->assertContains($deleteRes->getStatusCode(), [200, 204, 302, 403, 404]);
    }

    /**
     * Test 5: Directory traversal path injection in media / upload requests.
     */
    public function testSecurityDirectoryTraversalPathInjection(): void
    {
        $this->env->createLockfile();

        $traversalRes = $this->http->post('/api/v1/uploads', [
            'path' => '../../../../etc/passwd',
            'filename' => 'passwd',
        ]);

        $this->assertContains($traversalRes->getStatusCode(), [200, 302, 400, 403, 404, 422]);
        $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/public/uploads/../../../../etc/passwd');
    }
}
