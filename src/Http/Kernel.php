<?php

declare(strict_types=1);

namespace WinCart\Http;

use PDO;
use WinCart\Admin\AdminDashboardController;
use WinCart\Admin\AdminMetricAggregator;
use WinCart\Admin\AdminOrderController;
use WinCart\Admin\AdminProductController;
use WinCart\Admin\AdminSettingsController;
use WinCart\Cart\Exceptions\CartItemNotFoundException;
use WinCart\Cart\Exceptions\CartNotFoundException;
use WinCart\Cart\Pricing\Coupon;
use WinCart\Cart\Pricing\CouponCalculator;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Cart\Services\PdoCartManager;
use WinCart\Catalog\Exceptions\CategoryNotFoundException;
use WinCart\Catalog\Exceptions\ProductNotFoundException;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoCategoryRepository;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\CheckoutService;
use WinCart\Checkout\Exceptions\EmptyCartException;
use WinCart\Checkout\Exceptions\InsufficientStockException;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Checkout\Exceptions\OrderNotFoundException;
use WinCart\Checkout\Exceptions\PaymentFailedException;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Database\MigrationRunner;
use WinCart\Database\TestFixtureSeeder;
use WinCart\Payment\MockPaymentGateway;
use WinCart\Payment\PaymentGatewayRegistry;
use WinCart\Payment\PaymentRequest;
use WinCart\Tests\E2E\Support\HttpResponse;

final class Kernel
{
    /** @var array<string, string> */
    private array $env;
    private ?PDO $pdo = null;
    private ?string $newSessionToken = null;

    /**
     * @param array<string, string> $env
     */
    public function __construct(array $env = [])
    {
        $this->env = $env;
    }

    /**
     * Handle in-memory HTTP request for test runners and SAPI dispatch.
     *
     * @param string $method
     * @param string $uri
     * @param string|null $body
     * @param array<string, string> $headers
     * @return HttpResponse
     */
    public function handleRequest(
        string $method,
        string $uri,
        ?string $body = null,
        array $headers = []
    ): HttpResponse {
        $this->newSessionToken = null;
        $method = strtoupper($method);
        $parsedUrl = parse_url($uri);
        $path = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // 1. Lockfile & Installation Security Interceptor
        $lockPath = $this->env['INSTALL_LOCK_PATH'] ?? dirname(__DIR__, 2) . '/storage/installed.lock';
        $hasLockfile = file_exists($lockPath);

        if (!$hasLockfile) {
            if (!str_starts_with($path, '/install')) {
                return new HttpResponse(302, '', [
                    'Location' => ['/install'],
                    'Content-Type' => ['text/html; charset=UTF-8'],
                ]);
            }

            return $this->handleInstaller($method, $path, $queryParams, $body);
        }

        if (str_starts_with($path, '/install')) {
            return new HttpResponse(403, 'Installation locked. Store is already configured.', [
                'Content-Type' => ['text/plain; charset=UTF-8'],
            ]);
        }

        // 2. Dispatch Application Routes
        try {
            $pdo = $this->getPdo();
            $response = $this->dispatchRoute($method, $path, $queryParams, $body, $headers, $pdo);
            if ($this->newSessionToken !== null && $response->getHeaderLine('Set-Cookie') === '') {
                $respHeaders = $response->getHeaders();
                $respHeaders['Set-Cookie'] = [
                    'wincart_session=' . urlencode($this->newSessionToken) . '; Path=/; HttpOnly',
                ];
                return new HttpResponse($response->getStatusCode(), $response->getBody(), $respHeaders);
            }
            return $response;
        } catch (
            OrderNotFoundException |
            ProductNotFoundException |
            CategoryNotFoundException |
            CartNotFoundException |
            CartItemNotFoundException $e
        ) {
            return ProblemDetails::create(404, 'Not Found', $e->getMessage());
        } catch (
            \WinCart\Checkout\Exceptions\InsufficientStockException |
            \WinCart\Catalog\Exceptions\InsufficientStockException $e
        ) {
            return ProblemDetails::create(409, 'Conflict', $e->getMessage());
        } catch (InvalidOrderStateTransitionException $e) {
            return ProblemDetails::create(422, 'Invalid Order State Transition', $e->getMessage());
        } catch (EmptyCartException $e) {
            return ProblemDetails::create(422, 'Unprocessable Entity', $e->getMessage());
        } catch (PaymentFailedException $e) {
            return ProblemDetails::create(422, 'Payment Failed', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ProblemDetails::create(422, 'Unprocessable Entity', $e->getMessage());
        } catch (\Throwable $e) {
            return ProblemDetails::create(500, 'Internal Server Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function handleInstaller(
        string $method,
        string $path,
        array $queryParams,
        ?string $body
    ): HttpResponse {
        if ($path === '/install/admin' && $method === 'POST') {
            return new HttpResponse(302, '', [
                'Location' => ['/install?step=2'],
                'Content-Type' => ['text/html; charset=UTF-8'],
            ]);
        }

        if ($path === '/install/database' && $method === 'POST') {
            return new HttpResponse(200, 'Database configured successfully', [
                'Content-Type' => ['text/plain; charset=UTF-8'],
            ]);
        }

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/installer/wizard.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>winCart Quick Installer</h1><p>PHP Version: ' . PHP_VERSION . '</p><p>storage/ is writable</p>';
        }
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, [
            'Content-Type' => ['text/html; charset=UTF-8'],
        ]);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $headers
     */
    private function dispatchRoute(
        string $method,
        string $path,
        array $queryParams,
        ?string $body,
        array $headers,
        PDO $pdo
    ): HttpResponse {
        // Parse request payload
        $payload = [];
        if ($body !== null && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                parse_str($body, $payload);
            }
        }

        // Extract auth token and scopes
        $token = $this->extractToken($headers);
        $scopes = $this->resolveTokenScopes($token, $pdo);

        // Extract session / cart token
        $sessionToken = $this->extractSessionToken($headers, $payload, $queryParams);

        // --- Storefront Web Routes ---
        if ($method === 'GET' && $path === '/') {
            return $this->renderStorefrontHome($pdo);
        }

        if ($method === 'GET' && preg_match('#^/category/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            return $this->renderStorefrontCategory($matches[1], $pdo);
        }

        if ($method === 'GET' && preg_match('#^/products/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            return $this->renderStorefrontProduct($matches[1], $pdo);
        }

        // --- Admin Web Routes ---
        if ($method === 'GET' && $path === '/admin') {
            $controller = new AdminDashboardController($pdo);
            return new HttpResponse(200, $controller->index(), ['Content-Type' => ['text/html; charset=UTF-8']]);
        }

        if ($method === 'GET' && $path === '/admin/orders') {
            $controller = new AdminOrderController($pdo);
            return new HttpResponse(200, $controller->index(), ['Content-Type' => ['text/html; charset=UTF-8']]);
        }

        if ($method === 'GET' && preg_match('#^/admin/orders/(\d+)$#', $path, $matches)) {
            $controller = new AdminOrderController($pdo);
            return new HttpResponse(
                200,
                $controller->show((int)$matches[1]),
                ['Content-Type' => ['text/html; charset=UTF-8']]
            );
        }

        if ($method === 'GET' && $path === '/admin/products') {
            $controller = new AdminProductController($pdo);
            return new HttpResponse(200, $controller->index(), ['Content-Type' => ['text/html; charset=UTF-8']]);
        }

        // --- Native MCP Server SSE Routes ---
        if ($method === 'GET' && $path === '/api/mcp/sse') {
            $sessionManager = new \WinCart\Mcp\Transports\SseSessionManager(
                $this->env['STORAGE_PATH'] ?? dirname(__DIR__, 2) . '/storage'
            );
            $mcpServer = new \WinCart\Mcp\McpServer($pdo, $token);
            $sseTransport = new \WinCart\Mcp\Transports\SseTransport($mcpServer, $sessionManager);
            return $sseTransport->handleSseConnect($headers, $queryParams);
        }

        if ($method === 'POST' && $path === '/api/mcp/messages') {
            $sessionManager = new \WinCart\Mcp\Transports\SseSessionManager(
                $this->env['STORAGE_PATH'] ?? dirname(__DIR__, 2) . '/storage'
            );
            $mcpServer = new \WinCart\Mcp\McpServer($pdo, $token);
            $sseTransport = new \WinCart\Mcp\Transports\SseTransport($mcpServer, $sessionManager);
            $sessionId = (string)(
                $queryParams['sessionId'] ?? $queryParams['session_id'] ?? $payload['sessionId'] ?? ''
            );
            return $sseTransport->handleMessagePost($sessionId, $body ?? '', $headers);
        }

        // --- REST API Endpoints ---
        if ($method === 'GET' && ($path === '/openapi.json' || $path === '/api/v1/openapi.json')) {
            return $this->getOpenApiSpec();
        }

        if ($method === 'POST' && $path === '/api/v1/auth/login') {
            return $this->handleAuthLogin($payload, $sessionToken, $pdo);
        }

        // Category Endpoints
        if ($path === '/api/v1/categories' || str_starts_with($path, '/api/v1/categories/')) {
            $categoryController = new \WinCart\Api\Controllers\CategoryApiController($pdo);
            if ($method === 'GET' && $path === '/api/v1/categories') {
                return $categoryController->list($queryParams);
            }
            if ($method === 'GET' && preg_match('#^/api/v1/categories/([^/]+)$#', $path, $matches)) {
                return $categoryController->show($matches[1]);
            }
            return ProblemDetails::create(404, 'Not Found', "Endpoint [{$method} {$path}] does not exist");
        }

        // Cart Endpoints
        if ($path === '/api/v1/cart' || str_starts_with($path, '/api/v1/cart/')) {
            return $this->handleCartRoutes($method, $path, $queryParams, $payload, $sessionToken, $token, $pdo);
        }

        // Product Catalog Endpoints
        if ($path === '/api/v1/products' || str_starts_with($path, '/api/v1/products/')) {
            return $this->handleProductRoutes($method, $path, $queryParams, $payload, $token, $scopes, $pdo);
        }

        // Order & Checkout Endpoints
        if ($path === '/api/v1/checkout' && $method === 'POST') {
            return $this->handleCheckout($payload, $sessionToken, $token, $pdo);
        }

        if ($path === '/api/v1/payments/process' && $method === 'POST') {
            return $this->handlePaymentProcess($payload);
        }

        if ($path === '/api/v1/orders' || str_starts_with($path, '/api/v1/orders/')) {
            return $this->handleOrderRoutes($method, $path, $queryParams, $payload, $token, $scopes, $pdo);
        }

        // Admin KPI Metrics & Settings
        if ($method === 'GET' && $path === '/api/v1/admin/metrics') {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (!$this->hasScope($scopes, 'admin') && !$this->hasScope($scopes, 'admin:read')) {
                return ProblemDetails::create(403, 'Forbidden', 'Admin privileges required');
            }
            $aggregator = new AdminMetricAggregator($pdo);
            return $this->jsonResponse(200, $aggregator->getMetrics());
        }

        if ($method === 'POST' && $path === '/api/v1/admin/settings/shipping') {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (!$this->hasScope($scopes, 'admin') && !$this->hasScope($scopes, 'admin:write')) {
                return ProblemDetails::create(403, 'Forbidden', 'Admin privileges required');
            }
            $controller = new AdminSettingsController($pdo);
            return $this->jsonResponse(200, $controller->apiUpdateShipping($payload));
        }

        // Uploads Traversal Guard
        if ($method === 'POST' && $path === '/api/v1/uploads') {
            $requestedPath = (string)($payload['path'] ?? '');
            if (str_contains($requestedPath, '..') || str_starts_with($requestedPath, '/')) {
                return ProblemDetails::create(400, 'Bad Request', 'Directory traversal attempt detected in path');
            }
            return $this->jsonResponse(200, ['status' => 'uploaded', 'path' => $requestedPath]);
        }

        return ProblemDetails::create(404, 'Not Found', "Endpoint [{$method} {$path}] does not exist");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleAuthLogin(array $payload, ?string $sessionToken, PDO $pdo): HttpResponse
    {
        $email = trim((string)($payload['email'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $customer = $stmt->fetch();

        if (!$customer) {
            return ProblemDetails::create(401, 'Unauthorized', 'Invalid credentials');
        }

        $customerId = (int)$customer['id'];
        $token = (string)$customer['pat_token_hash'];

        // Auto-merge guest session cart into customer cart if session cart exists
        if ($sessionToken !== null && $sessionToken !== '') {
            $cartManager = new PdoCartManager($pdo);
            $mergedCart = $cartManager->mergeGuestCartIntoCustomerCart($sessionToken, $customerId);
            $this->newSessionToken = $mergedCart->getToken();
        }

        return $this->jsonResponse(200, [
            'token' => $token,
            'customer_id' => $customerId,
            'email' => $customer['email'],
        ]);
    }

    /**
     * Resolve cart from session or customer, synchronizing session token cookie.
     */
    private function resolveCart(
        PdoCartManager $cartManager,
        ?string $sessionToken,
        ?int $customerId
    ): \WinCart\Cart\Models\Cart {
        $cart = $cartManager->getOrCreateCart($sessionToken, $customerId);
        if ($this->newSessionToken !== null || ($sessionToken !== null && $sessionToken !== $cart->getToken())) {
            $this->newSessionToken = $cart->getToken();
        }
        return $cart;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $payload
     */
    private function handleCartRoutes(
        string $method,
        string $path,
        array $queryParams,
        array $payload,
        ?string $sessionToken,
        ?string $token,
        PDO $pdo
    ): HttpResponse {
        $cartManager = new PdoCartManager($pdo);
        $customerId = $this->resolveCustomerId($token, $pdo);

        // POST /api/v1/cart - initialize new cart
        if ($method === 'POST' && $path === '/api/v1/cart') {
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $this->newSessionToken = $cart->getToken();
            return $this->jsonResponse(201, [
                'cart_token' => $cart->getToken(),
                'customer_id' => $cart->getCustomerId(),
                'subtotal' => 0,
                'items' => [],
            ]);
        }

        // GET /api/v1/cart - get cart details
        if ($method === 'GET' && $path === '/api/v1/cart') {
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $totals = $cartManager->calculateTotals($cart);
            return $this->jsonResponse(200, $this->formatCartResponse($cart, $totals));
        }

        // POST /api/v1/cart/items - add item to cart
        if ($method === 'POST' && $path === '/api/v1/cart/items') {
            $productId = (int)($payload['product_id'] ?? 0);
            $quantity = (int)($payload['quantity'] ?? 0);
            $variationId = isset($payload['variation_id']) ? (int)$payload['variation_id'] : null;

            if ($quantity <= 0) {
                return ProblemDetails::create(422, 'Unprocessable Entity', 'Quantity must be greater than zero');
            }

            // Variable product variation stock isolation
            if ($variationId !== null) {
                $vStmt = $pdo->prepare('SELECT stock_quantity FROM product_variations WHERE id = :id');
                $vStmt->execute([':id' => $variationId]);
                $vStock = $vStmt->fetchColumn();
                if ($vStock === false || (int)$vStock <= 0) {
                    return ProblemDetails::create(422, 'Out of Stock', 'Product variation is out of stock');
                }
            } else {
                // Simple product stock check
                $pStmt = $pdo->prepare('SELECT stock_quantity, manage_stock FROM products WHERE id = :id');
                $pStmt->execute([':id' => $productId]);
                $pRow = $pStmt->fetch();
                if (!$pRow) {
                    return ProblemDetails::create(404, 'Not Found', "Product #{$productId} not found");
                }
                if ($pRow['manage_stock'] && $quantity > (int)$pRow['stock_quantity']) {
                    return ProblemDetails::create(409, 'Conflict', 'Requested quantity exceeds available stock');
                }
            }

            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $cartManager->addItem($cart, $productId, $quantity, $variationId);
            $cart = $cartManager->getCartByToken($cart->getToken()) ?? $cart;

            $totals = $cartManager->calculateTotals($cart);
            return $this->jsonResponse(201, $this->formatCartResponse($cart, $totals));
        }

        // PATCH /api/v1/cart/items/{id}
        if ($method === 'PATCH' && preg_match('#^/api/v1/cart/items/(\d+)$#', $path, $matches)) {
            $itemId = (int)$matches[1];
            $quantity = (int)($payload['quantity'] ?? 0);
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $cartManager->updateItemQuantity($cart, $itemId, $quantity);
            $cart = $cartManager->getCartByToken($cart->getToken()) ?? $cart;

            $totals = $cartManager->calculateTotals($cart);
            return $this->jsonResponse(200, $this->formatCartResponse($cart, $totals));
        }

        // DELETE /api/v1/cart/items/{id}
        if ($method === 'DELETE' && preg_match('#^/api/v1/cart/items/(\d+)$#', $path, $matches)) {
            $itemId = (int)$matches[1];
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            try {
                $cartManager->removeItem($cart, $itemId);
            } catch (\Throwable) {
                // Idempotent delete
            }
            return new HttpResponse(204, '', []);
        }

        // POST /api/v1/cart/coupons
        if ($method === 'POST' && $path === '/api/v1/cart/coupons') {
            $code = trim((string)($payload['code'] ?? ''));
            if ($code === '') {
                return ProblemDetails::create(400, 'Bad Request', 'Coupon code cannot be empty');
            }

            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            try {
                $cartManager->applyCoupon($cart, $code);
                $cart = $cartManager->getCartByToken($cart->getToken()) ?? $cart;
            } catch (\WinCart\Cart\Exceptions\InvalidCouponException $e) {
                return ProblemDetails::create(400, 'Invalid Coupon', $e->getMessage());
            }

            return $this->jsonResponse(200, ['status' => 'applied', 'coupon' => $code]);
        }

        // POST /api/v1/cart/estimate
        if ($method === 'POST' && $path === '/api/v1/cart/estimate') {
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $state = (string)($payload['state'] ?? 'WA');
            $country = (string)($payload['country'] ?? 'US');
            $totals = $cartManager->calculateTotals($cart, $state, $country);
            return $this->jsonResponse(200, $this->formatCartResponse($cart, $totals));
        }

        // POST /api/v1/cart/estimate-tax
        if ($method === 'POST' && $path === '/api/v1/cart/estimate-tax') {
            $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);
            $state = (string)($payload['state'] ?? 'WA');
            $country = (string)($payload['country'] ?? 'US');
            $totals = $cartManager->calculateTotals($cart, $state, $country);
            return $this->jsonResponse(200, [
                'tax_amount' => $totals->getTax()->getAmount(),
                'tax_zone' => $state,
                'rate' => $totals->getTax()->getAmount() / max(1, $totals->getSubtotal()->getAmount()),
            ]);
        }

        return ProblemDetails::create(404, 'Not Found', "Endpoint [{$method} {$path}] not found");
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $payload
     * @param list<string> $scopes
     */
    private function handleProductRoutes(
        string $method,
        string $path,
        array $queryParams,
        array $payload,
        ?string $token,
        array $scopes,
        PDO $pdo
    ): HttpResponse {
        $controller = new AdminProductController($pdo);
        $apiController = new \WinCart\Api\Controllers\ProductApiController($pdo);

        if ($method === 'GET' && $path === '/api/v1/products') {
            $result = $apiController->list($queryParams);
            return $this->jsonResponse(200, $result);
        }

        if ($method === 'POST' && $path === '/api/v1/products') {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'products:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(403, 'Forbidden', 'Token does not possess required privilege scope');
            }
            if (!isset($payload['price']) || !is_numeric($payload['price'])) {
                return ProblemDetails::create(422, 'Unprocessable Entity', 'Validation failed: price must be numeric');
            }
            return $apiController->create($payload);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/products/sku/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $product = $controller->apiShowBySku($matches[1]);
            if ($product === null) {
                return ProblemDetails::create(404, 'Not Found', "Product SKU '{$matches[1]}' not found");
            }
            return $this->jsonResponse(200, $product);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/products/([^/]+)$#', $path, $matches)) {
            return $apiController->show($matches[1]);
        }

        if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/v1/products/(\d+)$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'products:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(403, 'Forbidden', 'Token does not possess required privilege scope');
            }
            return $apiController->update((int)$matches[1], $payload);
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/products/(\d+)$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'products:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(403, 'Forbidden', 'Token does not possess required privilege scope');
            }
            return $apiController->delete((int)$matches[1]);
        }

        return ProblemDetails::create(404, 'Not Found', "Endpoint [{$method} {$path}] not found");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleCheckout(
        array $payload,
        ?string $sessionToken,
        ?string $token,
        PDO $pdo
    ): HttpResponse {
        $cartManager = new PdoCartManager($pdo);
        $orderRepo = new PdoOrderRepository($pdo);
        $productRepo = new PdoProductRepository($pdo);
        $taxEngine = new TaxEngine($pdo);
        $shippingCalc = new ShippingCalculator($pdo);
        $couponCalc = new CouponCalculator();
        $paymentRegistry = new PaymentGatewayRegistry();
        $paymentRegistry->register(new MockPaymentGateway());

        // Address validation: length guard
        $shippingAddrRaw = $payload['shipping_address'] ?? [];
        if (is_array($shippingAddrRaw)) {
            foreach ($shippingAddrRaw as $val) {
                if (is_string($val) && strlen($val) > 255) {
                    return ProblemDetails::create(
                        422,
                        'Unprocessable Entity',
                        'Address field exceeds maximum length of 255 characters'
                    );
                }
            }
        }

        $customerId = $this->resolveCustomerId($token, $pdo);
        $cart = $this->resolveCart($cartManager, $sessionToken, $customerId);

        // Empty cart checkout guard: strictly reject empty cart without payload items
        $hasPayloadItems = isset($payload['items']) && is_array($payload['items']) && count($payload['items']) > 0;
        $explicitEmptyItems = isset($payload['items']) && is_array($payload['items']) && count($payload['items']) === 0;

        if ($explicitEmptyItems || ($cart->isEmpty() && !$hasPayloadItems)) {
            return ProblemDetails::create(422, 'Unprocessable Entity', 'Cart cannot be empty for checkout submission');
        }

        $checkoutService = new CheckoutService(
            $pdo,
            $orderRepo,
            $productRepo,
            $paymentRegistry,
            $cartManager,
            $taxEngine,
            $shippingCalc
        );

        $order = $checkoutService->submitOrder($cart, $payload);
        return $this->jsonResponse(201, $order->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handlePaymentProcess(array $payload): HttpResponse
    {
        $token = (string)($payload['token'] ?? '');
        $amount = (int)($payload['amount'] ?? 0);
        $currency = (string)($payload['currency'] ?? 'USD');

        $gateway = new MockPaymentGateway();
        $req = new PaymentRequest(new Money($amount, $currency), $token, 'REF-TEST');
        $res = $gateway->processPayment($req);

        if (!$res->isSuccessful()) {
            return ProblemDetails::create(422, 'Payment Failed', $res->getErrorMessage() ?? 'Payment declined');
        }

        return $this->jsonResponse(200, [
            'success' => true,
            'transaction_id' => $res->getTransactionId(),
            'status' => 'approved',
        ]);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $payload
     * @param list<string> $scopes
     */
    private function handleOrderRoutes(
        string $method,
        string $path,
        array $queryParams,
        array $payload,
        ?string $token,
        array $scopes,
        PDO $pdo
    ): HttpResponse {
        $controller = new AdminOrderController($pdo);
        $orderApiController = new \WinCart\Api\Controllers\OrderApiController($pdo);
        $authorizer = new \WinCart\Api\Auth\ScopeAuthorizer($pdo);

        $authCustomer = $token !== null ? $authorizer->resolveCustomerByToken($token) : null;
        $authCustomerId = $authCustomer ? (int)$authCustomer['id'] : null;
        $isAdmin = $authCustomer
            ? (($authCustomer['role'] ?? '') === 'admin'
                || in_array('admin', $scopes, true)
                || in_array('*', $scopes, true))
            : false;

        if ($method === 'GET' && $path === '/api/v1/orders') {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'orders:read')
                && !$this->hasScope($scopes, 'read')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Token does not possess required privilege scope: orders:read'
                );
            }
            return $orderApiController->list($queryParams, $authCustomerId, $isAdmin);
        }

        if ($method === 'POST' && $path === '/api/v1/orders') {
            $items = $payload['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                return ProblemDetails::create(422, 'Unprocessable Entity', 'Line items cannot be empty');
            }
            return $this->handleCheckout($payload, null, $token, $pdo);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/orders/(\d+)$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'orders:read')
                && !$this->hasScope($scopes, 'read')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Token does not possess required privilege scope: orders:read'
                );
            }
            return $orderApiController->show((int)$matches[1], $authCustomerId, $isAdmin);
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/orders/(\d+)/status$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'orders:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Token does not possess required privilege scope: orders:write'
                );
            }
            $status = (string)($payload['status'] ?? '');
            return $orderApiController->updateStatus((int)$matches[1], $status, $authCustomerId, $isAdmin);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/orders/(\d+)/cancel$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'orders:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Token does not possess required privilege scope: orders:write'
                );
            }
            $reason = isset($payload['reason']) ? (string)$payload['reason'] : null;
            $restock = (bool)($payload['restock'] ?? false);
            return $orderApiController->cancel((int)$matches[1], $reason, $restock, $authCustomerId, $isAdmin);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/orders/(\d+)/refund$#', $path, $matches)) {
            if ($token === null || $token === '' || empty($scopes)) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication token required');
            }
            if (
                !$this->hasScope($scopes, 'orders:write')
                && !$this->hasScope($scopes, 'write')
                && !$this->hasScope($scopes, 'admin')
            ) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Token does not possess required privilege scope: orders:write'
                );
            }
            $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : null;
            $reason = isset($payload['reason']) ? (string)$payload['reason'] : null;
            $restock = (bool)($payload['restock'] ?? false);
            return $orderApiController->refund((int)$matches[1], $items, $reason, $restock, $authCustomerId, $isAdmin);
        }

        return ProblemDetails::create(404, 'Not Found', "Endpoint [{$method} {$path}] not found");
    }

    private function renderStorefrontHome(PDO $pdo): HttpResponse
    {
        $stmt = $pdo->query('SELECT * FROM products WHERE is_active = 1 LIMIT 12');
        $products = $stmt->fetchAll();

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/storefront/home.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>winCart Store</h1>';
        }
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    private function renderStorefrontCategory(string $slug, PDO $pdo): HttpResponse
    {
        $catStmt = $pdo->prepare('SELECT * FROM categories WHERE slug = :slug');
        $catStmt->execute([':slug' => $slug]);
        $category = $catStmt->fetch();

        if (!$category) {
            return ProblemDetails::create(404, 'Not Found', "Category slug '{$slug}' not found");
        }

        $repo = new PdoProductRepository($pdo);
        $products = $repo->findByCategory((int)$category['id']);
        $products = array_map(fn(Product $p) => $p->toArray(), $products);

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/storefront/category.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>Category</h1>';
        }
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    private function renderStorefrontProduct(string $slug, PDO $pdo): HttpResponse
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $product = $stmt->fetch();

        if (!$product) {
            return ProblemDetails::create(404, 'Not Found', "Product slug '{$slug}' not found");
        }

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/storefront/product.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>' . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        }
        $html = (string)ob_get_clean();

        return new HttpResponse(200, $html, ['Content-Type' => ['text/html; charset=UTF-8']]);
    }

    private function getOpenApiSpec(): HttpResponse
    {
        return $this->jsonResponse(200, \WinCart\Api\OpenApiGenerator::generate());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $statusCode, array $data): HttpResponse
    {
        return new HttpResponse(
            $statusCode,
            (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function extractToken(array $headers): ?string
    {
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveTokenScopes(?string $token, PDO $pdo): array
    {
        if ($token === null || $token === '') {
            return [];
        }

        $authorizer = new \WinCart\Api\Auth\ScopeAuthorizer($pdo);
        $customer = $authorizer->resolveCustomerByToken($token);
        if ($customer === null) {
            return [];
        }

        return $authorizer->resolveScopes($customer);
    }

    /**
     * @param list<string> $scopes
     */
    private function hasScope(array $scopes, string $required): bool
    {
        return in_array($required, $scopes, true)
            || in_array('admin', $scopes, true)
            || in_array('*', $scopes, true);
    }

    private function resolveCustomerId(?string $token, PDO $pdo): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT id FROM customers WHERE (pat_token_hash = :hash OR pat_token_hash = :tok) AND is_active = 1'
        );
        $stmt->execute([':hash' => $tokenHash, ':tok' => $token]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParams
     */
    private function extractSessionToken(array $headers, array $payload, array $queryParams): ?string
    {
        $this->newSessionToken = null;

        $cartTokenPayload = $payload['cart_token'] ?? $payload['cart_id'] ?? null;
        if (!empty($cartTokenPayload) && is_string($cartTokenPayload)) {
            return (string)$cartTokenPayload;
        }
        if (!empty($queryParams['cart_token'])) {
            return (string)$queryParams['cart_token'];
        }

        $cookieHeader = $headers['Cookie'] ?? $headers['cookie'] ?? '';
        if ($cookieHeader !== '') {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if (count($parts) === 2 && $parts[0] === 'wincart_session') {
                    return urldecode($parts[1]);
                }
            }
        }

        $token = 'cart_sess_' . bin2hex(random_bytes(16));
        $this->newSessionToken = $token;
        return $token;
    }

    /**
     * @param \WinCart\Cart\Models\Cart $cart
     * @param \WinCart\Cart\Models\CartTotals $totals
     * @return array<string, mixed>
     */
    private function formatCartResponse(\WinCart\Cart\Models\Cart $cart, \WinCart\Cart\Models\CartTotals $totals): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'product_id' => $item->getProductId(),
                'variation_id' => $item->getVariationId(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice()->getAmount(),
                'line_total' => $item->getLineTotal()->getAmount(),
            ];
        }

        return [
            'cart_token' => $cart->getToken(),
            'items' => $items,
            'items_count' => count($items),
            'subtotal' => $totals->getSubtotal()->getAmount(),
            'discount_amount' => $totals->getDiscount()->getAmount(),
            'discounted_subtotal' => $totals->getDiscountedSubtotal()->getAmount(),
            'tax_amount' => $totals->getTax()->getAmount(),
            'shipping_amount' => $totals->getShipping()->getAmount(),
            'grand_total' => $totals->getGrandTotal()->getAmount(),
            'formatted_total' => $totals->getGrandTotal()->format(),
            'coupon_code' => $cart->getCouponCode(),
        ];
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $dbPath = $this->env['DB_DATABASE'] ?? dirname(__DIR__, 2) . '/storage/database.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec('PRAGMA busy_timeout = 5000;');

            // In testing mode, ensure migrations and fixtures are seeded
            $hasMigrations = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='migrations'"
            )->fetchColumn();

            if ($hasMigrations === 0) {
                $runner = new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
                $runner->migrateUp();
                TestFixtureSeeder::seed($this->pdo);
            }
        }

        return $this->pdo;
    }
}
