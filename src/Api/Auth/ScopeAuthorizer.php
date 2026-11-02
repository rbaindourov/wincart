<?php

declare(strict_types=1);

namespace WinCart\Api\Auth;

use PDO;
use WinCart\Http\ProblemDetails;
use WinCart\Tests\E2E\Support\HttpResponse;

final class ScopeAuthorizer
{
    /** @var list<string> */
    public const ALL_SCOPES = [
        'products:read',
        'products:write',
        'orders:read',
        'orders:write',
        'cart:read',
        'admin:read',
        'admin:write',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Resolve authentication and verify required scope.
     * Returns null if authorized, or HttpResponse (401/403) if rejected.
     *
     * @param array<string, string> $headers
     */
    public function authorize(array $headers, string $requiredScope): ?HttpResponse
    {
        $token = $this->extractBearerToken($headers);
        if ($token === null || $token === '') {
            return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
        }

        $customer = $this->resolveCustomerByToken($token);
        if ($customer === null) {
            return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
        }

        $scopes = $this->resolveScopes($customer);
        if (!$this->hasScope($scopes, $requiredScope, (string)($customer['role'] ?? 'customer'))) {
            return ProblemDetails::create(
                403,
                'Forbidden',
                "Token does not possess required privilege scope: {$requiredScope}"
            );
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function extractBearerToken(array $headers): ?string
    {
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveCustomerByToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customers WHERE (pat_token_hash = :hash OR pat_token_hash = :raw) AND is_active = 1'
        );
        $stmt->execute([':hash' => $tokenHash, ':raw' => $token]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $customer
     * @return list<string>
     */
    public function resolveScopes(array $customer): array
    {
        $role = (string)($customer['role'] ?? 'customer');
        if ($role === 'admin') {
            return array_values(array_unique(array_merge(self::ALL_SCOPES, ['admin', '*'])));
        }

        $rawScopes = (string)($customer['pat_scopes'] ?? '');
        if ($rawScopes === '') {
            return [];
        }

        $declared = array_map('trim', explode(',', $rawScopes));
        $expanded = [];

        foreach ($declared as $s) {
            $expanded[] = $s;
            if ($s === 'admin' || $s === '*') {
                return array_values(array_unique(array_merge(self::ALL_SCOPES, ['admin', '*'])));
            }
            if ($s === 'write') {
                $expanded[] = 'products:write';
                $expanded[] = 'orders:write';
                $expanded[] = 'products:read';
                $expanded[] = 'orders:read';
            } elseif ($s === 'read') {
                $expanded[] = 'products:read';
                $expanded[] = 'orders:read';
                $expanded[] = 'cart:read';
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param list<string> $scopes
     */
    public function hasScope(array $scopes, string $requiredScope, string $role = 'customer'): bool
    {
        if ($role === 'admin') {
            return true;
        }
        if (in_array('admin', $scopes, true) || in_array('*', $scopes, true)) {
            return true;
        }
        return in_array($requiredScope, $scopes, true);
    }
}
