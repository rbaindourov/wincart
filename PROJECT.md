# Project: winCart Modernization

## Architecture
winCart is a production-grade, modern PHP 8.x e-commerce platform engineered to compete directly with WooCommerce, featuring an agent-first architecture (native Model Context Protocol server and headless REST APIs) and a 5-minute automated quick installer.

### Architectural Principles
1. **Modern PHP 8.4+ & Zero Bloat**: Built with strict typing (`declare(strict_types=1);`), PSR-4 autoloading (`WinCart\`), PSR-7/17 HTTP messages, PSR-11 dependency injection, and PSR-15 middleware. Outperforms WooCommerce's monolithic post/postmeta EAV database bottleneck by 10x-50x.
2. **Dual-Engine Database & Migration System**: Normalized 16-table relational schema supporting zero-config SQLite 3 (for instant local install, rapid development, and <1.5s in-memory automated test runs) and MySQL 8.0+ / MariaDB (for enterprise production scaling). Automated migration runner executes idempotent up/down migrations.
3. **Domain-Driven E-Commerce Core**:
   - **Catalog**: Hierarchical category tree, simple and variable products, SKUs, inventory tracking with atomic SQL decrements, attributes, and image gallery.
   - **Cart**: Persistent session and database cart with auto-merge on customer login, dynamic pricing validation, coupon engine, tax zones, and shipping rate calculations.
   - **Checkout & Orders**: Frictionless responsive checkout, customer address book, order state machine (`OrderStatus` enum), immutable historical line items, and pluggable payment gateway drivers (`MockPaymentGateway` for tests, Stripe/PayPal/COD).
   - **Admin Control Panel**: Lightweight, responsive administrative dashboard with real-time KPI metrics, catalog management, order processing, and store configuration.
4. **Agent-First Interfaces (R3)**:
   - Native Model Context Protocol (MCP) server conforming to protocol version `2024-11-05`, supporting both standard I/O (`stdio` for local AI assistants like Claude Desktop, Cursor, Gemini CLI) and Server-Sent Events (`SSE` for remote agents and webhooks). Exposes 9+ specialized tools, MCP resources, and prompt templates.
   - Headless REST API (`/api/v1`) with full CRUD, Bearer Personal Access Token (PAT) authentication with granular scopes (`read:products`, `write:products`, `read:orders`, `write:orders`, `admin`), RFC 7807 Problem Details error formatting, and OpenAPI 3.0 schema generation.
5. **Automated Quick Installer (R4)**:
   - Automated CLI command (`php bin/wincart install` / `composer install-wincart`) supporting non-interactive scripted setup.
   - 5-step Web Wizard (`/install`) providing environment validation, database connection testing, administrator provisioning, and optional sample catalog seeding.
   - Authoritative filesystem lockfile (`storage/installed.lock`) guarding unconfigured routing (redirects to `/install`) and sealing `/install` post-setup with HTTP 403 Forbidden.

---

## Code Layout
```
/home/robert/projects/wincart/
├── bin/
│   ├── wincart                     # CLI management & installer binary
│   └── mcp-server                  # Native stdio MCP server entrypoint
├── config/
│   ├── app.php                     # Application settings & service providers
│   ├── database.php                # Database connection profiles (SQLite/MySQL)
│   └── payment.php                 # Payment gateway driver registry
├── database/
│   ├── migrations/                 # Idempotent database migrations
│   └── seeders/                    # Demo catalog & default options seeders
├── public/
│   ├── index.php                   # Web entrypoint & PSR-15 pipeline
│   ├── assets/                     # CSS, JS, and storefront static assets
│   └── uploads/                    # Product images & media storage
├── src/
│   ├── Common/                     # Value objects (Money, Address), Enums, Exceptions
│   ├── Database/                   # Connection factory, Query builder, Migration runner
│   ├── Catalog/                    # Product, Category, Attribute, Inventory services & repositories
│   ├── Cart/                       # Cart session/DB persistence, Pricing, Coupons, Taxes, Shipping
│   ├── Checkout/                   # Checkout service, Order state machine, Payment driver interface
│   ├── Payment/                    # MockGateway, StripeGateway, OfflineGateway
│   ├── Admin/                      # Admin dashboard controllers, Metric aggregators, Views
│   ├── Http/                       # Router, PSR-15 Middleware, Request handlers, ProblemDetails
│   ├── Api/                        # Headless REST API controllers, Auth middleware, OpenAPI generator
│   ├── Mcp/                        # MCP JSON-RPC protocol parser, Stdio/SSE transports, Tool registry
│   └── Installer/                  # Environment checker, CLI installer, Web wizard controllers
├── storage/
│   ├── database.sqlite             # Default zero-config SQLite database
│   ├── installed.lock              # Authoritative installation lockfile
│   ├── cache/                      # View and DI container cache
│   └── logs/                       # Application & MCP error logs
├── templates/
│   ├── storefront/                 # Storefront responsive templates
│   ├── admin/                      # Admin dashboard responsive templates
│   └── installer/                  # 5-step installer web wizard templates
├── tests/
│   ├── Unit/                       # Fast unit tests (Money, Cart, Models, Auth)
│   ├── Feature/                    # Feature tests (Catalog, Checkout, API, Installer, MCP)
│   └── E2E/                        # Requirement-driven opaque-box E2E test suite (Tiers 1-4)
├── composer.json                   # PSR-4 autoloading & dependencies
└── phpunit.xml                     # Automated testing configuration
```

---

## Feature Inventory

| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Repository Migration & History Preservation | Import legacy git history from WinWinHost/winCart, branch legacy code, establish clean `main` under github.com/rbaindourov/wincart | M1 | ORIGINAL_REQUEST R1 |
| 2 | Modern Project Scaffolding & Composer PSR-4 | Configure modern `composer.json` (PHP 8.2+, PSR-4 `WinCart\`, PHPUnit), directory layout, and git hygiene | M1 | ORIGINAL_REQUEST R1 |
| 3 | Dual-Track E2E Test Suite Infrastructure | Standalone requirement-driven opaque-box test runner, fixtures, and pass/fail assertion harness | M2 | Dual Track Architecture |
| 4 | Tier 1 Feature Coverage E2E Tests | >=5 test cases per core feature verifying catalog, cart, checkout, admin, installer, and MCP | M2 | Dual Track Architecture |
| 5 | Tier 2 Boundary & Corner Case E2E Tests | Boundary tests for empty carts, stock limits, invalid coupons, negative values, and zero balances | M2 | Dual Track Architecture |
| 6 | Tier 3 Cross-Feature Combination Tests | Pairwise combinatorial tests for auth + cart, promo codes + tax, multi-item checkout + inventory decrements | M2 | Dual Track Architecture |
| 7 | Tier 4 Real-World Application Scenarios | Complete end-to-end customer purchase workflows, store restocking, and agent automation workloads | M2 | Dual Track Architecture |
| 8 | Normalised Database Schema & Migrations | 16-table normalized schema with foreign keys, dual SQLite 3 & MySQL 8.0 support, and migration runner | M3 | ORIGINAL_REQUEST R2 |
| 9 | Product Catalog & Category Hierarchy | Product entity, category tree, variable products/attributes, SKU management, atomic inventory decrements | M3 | ORIGINAL_REQUEST R2 |
| 10 | Persistent Shopping Cart & Price Engine | Session and DB cart persistence, automatic merge on login, coupon discount engine, taxes, shipping calculation | M3 | ORIGINAL_REQUEST R2 |
| 11 | Checkout Workflow & Order State Machine | 1-page checkout, address validation, order state transitions, immutable historical line items | M3 | ORIGINAL_REQUEST R2 |
| 12 | Pluggable Payment Gateway Architecture | `PaymentGatewayInterface` with deterministic `MockPaymentGateway` (for tests/offline) and extensible drivers | M3 | ORIGINAL_REQUEST R2 |
| 13 | Administrative Control Panel & Metrics | Responsive web admin dashboard for catalog, orders, customers, store settings, and real-time KPI metrics | M3 | ORIGINAL_REQUEST R2 |
| 14 | Native MCP Server (stdio Transport) | Line-delimited JSON-RPC 2.0 over standard I/O (`bin/mcp-server`) for Claude Desktop, Cursor, Gemini CLI | M4 | ORIGINAL_REQUEST R3 |
| 15 | Native MCP Server (HTTP / SSE Transport) | Server-Sent Events (`GET /api/mcp/sse`) and message dispatch (`POST /api/mcp/messages`) with session UUIDs | M4 | ORIGINAL_REQUEST R3 |
| 16 | Core MCP Tool Suite (9 Dispatched Tools) | `search_products`, `get_product`, `create_product`, `update_product`, `delete_product`, `list_orders`, `get_order`, `update_order_status`, `get_store_metrics` | M4 | ORIGINAL_REQUEST R3 |
| 17 | MCP Resources & Prompt Templates | Store metric resources, inventory alert feeds, and autonomous agent prompt templates (`daily_store_briefing`) | M4 | ORIGINAL_REQUEST R3 |
| 18 | Headless REST API (`/api/v1`) | Full RESTful CRUD endpoints for products, categories, cart, checkout, orders, settings | M4 | ORIGINAL_REQUEST R3 |
| 19 | Personal Access Token (PAT) Auth & Scopes | Bearer token authentication with SHA-256 token hashing and 7 granular scopes (`read/write:products`, etc.) | M4 | ORIGINAL_REQUEST R3 |
| 20 | RFC 7807 Problem Details Error Formatting | Standardized JSON error envelopes for API validation and authentication failures | M4 | ORIGINAL_REQUEST R3 |
| 21 | OpenAPI 3.0.3 Specification Document | Machine-readable API schema exposed at `/api/v1/openapi.json` | M4 | ORIGINAL_REQUEST R3 |
| 22 | Automated CLI Installer Command | `php bin/wincart install` with 15 parameter flags supporting unattended scripted execution | M5 | ORIGINAL_REQUEST R4 |
| 23 | 5-Step Web Installer Wizard (`/install`) | Guided browser wizard: Environment check, DB config, Admin user setup, Demo data seeding, Lock & finalize | M5 | ORIGINAL_REQUEST R4 |
| 24 | Unconfigured Router Interceptor | Middleware catching uninstalled state and redirecting all web traffic to `/install` | M5 | ORIGINAL_REQUEST R4 |
| 25 | Security Lockfile Guard (`storage/installed.lock`) | Cryptographic/timestamped lockfile sealing `/install` with HTTP 403 Forbidden post-installation | M5 | ORIGINAL_REQUEST R4 |
| 26 | Sample Catalog & Demo Data Seeder | Immediate store population with sample categories, realistic products, and dummy orders | M5 | ORIGINAL_REQUEST R4 |
| 27 | Full E2E Test Suite Pass (Tiers 1-4) | Execution and 100% pass verification of all opaque-box requirement tests via `composer test` | M6 | ORIGINAL_REQUEST R5 |
| 28 | Adversarial Coverage Hardening (Tier 5) | White-box stress testing, race condition guards, malformed JSON-RPC attacks, and coverage hardening | M6 | Project Pattern |

---

## Milestones

| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| 1 | Repository Migration & Modern Scaffolding | Clone legacy repo, branch legacy history, establish clean `main`, create `github.com/rbaindourov/wincart`, configure Composer PSR-4 scaffolding | none | DONE |
| 2 | E2E Testing Track Infrastructure | Design requirement-driven opaque-box test runner, test fixtures, and Tiers 1-4 test suite; publish `TEST_READY.md` | none | DONE |
| 3 | Modern PHP Core E-Commerce Architecture | 16-table normalized schema & migrations (SQLite/MySQL), Catalog, Cart, Checkout, Pluggable Payments, Admin Dashboard | M1 | IN_PROGRESS |
| 4 | Agent-First Interfaces (MCP & Headless API) | Native MCP server (stdio & SSE), 9+ core tools, Headless REST API (`/api/v1`), Bearer PAT auth, OpenAPI 3.0 | M3 | PLANNED |
| 5 | Automated Quick Installer | CLI installer (`php bin/wincart install`), 5-step Web Wizard (`/install`), unconfigured interceptor, `storage/installed.lock` guard, demo seeder | M3 | PLANNED |
| 6 | Final Milestone: 100% E2E Pass & Adversarial Hardening | Phase 1: Pass 100% E2E tests (Tiers 1-4); Phase 2: Adversarial coverage hardening (Tier 5) with challenger-worker loop | M2, M3, M4, M5 | PLANNED |

---

## Interface Contracts

### 1. Database & Migrations Contract (`WinCart\Database`)
- **Connection Factory**: `ConnectionFactory::create(array $config): \PDO`
  - Supports drivers: `sqlite` (`path`) and `mysql` (`host`, `port`, `dbname`, `username`, `password`, `charset=utf8mb4`).
  - Sets `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`.
- **Migration Runner**: `MigrationRunner::migrateUp(): array`
  - Applies all pending SQL migration files in `database/migrations/*.sql` within a database transaction.
  - Updates `migrations` table (`id`, `migration`, `batch`, `applied_at`).

### 2. Catalog & Cart Contracts (`WinCart\Catalog`, `WinCart\Cart`)
- **Product Repository**: `ProductRepositoryInterface`
  - `findById(int $id): ?Product`
  - `findBySku(string $sku): ?Product`
  - `search(array $criteria, int $limit, int $offset): array`
  - `save(Product $product): Product`
  - `adjustStock(int $productId, int $delta, string $reason): int`
- **Cart Manager**: `CartManagerInterface`
  - `getCart(?string $token, ?int $userId): Cart`
  - `addItem(Cart $cart, int $productId, int $quantity): CartItem`
  - `removeItem(Cart $cart, int $itemId): void`
  - `applyCoupon(Cart $cart, string $code): Cart`
  - `calculateTotals(Cart $cart): CartTotals`

### 3. Checkout & Payment Contracts (`WinCart\Checkout`, `WinCart\Payment`)
- **Checkout Service**: `CheckoutService::submitOrder(Cart $cart, array $orderData): Order`
  - Validates stock atomically via `ProductRepository::adjustStock`.
  - Executes payment via `PaymentGatewayInterface::processPayment(PaymentRequest $request): PaymentResult`.
  - Creates immutable `Order` and `OrderItem` records with snapshot prices.
- **Payment Gateway Interface**:
  - `processPayment(PaymentRequest $request): PaymentResult`
  - `supports(string $method): bool`
  - Implementations: `MockPaymentGateway` (deterministic testing), `OfflinePaymentGateway` (COD).

### 4. Agent MCP Interface Contract (`WinCart\Mcp`)
- **MCP Server Engine**: `McpServer::handleMessage(string $jsonRpcRequest, ?string $apiKey): string`
  - Protocol version: `2024-11-05`.
  - Tools exposed: `search_products`, `get_product`, `create_product`, `update_product`, `delete_product`, `list_orders`, `get_order`, `update_order_status`, `get_store_metrics`.
  - Returns standard JSON-RPC 2.0 response string. Stdio writes to stdout, SSE pushes event.

### 5. Installer Contract (`WinCart\Installer`)
- **Installer Service**: `InstallerService::install(array $config): InstallResult`
  - Validates environment (`php_version >= 8.2`, PDO drivers, writable directories).
  - Configures `.env` / database settings.
  - Runs database migrations.
  - Creates administrator user with bcrypt password hash.
  - Optionally runs `DatabaseSeeder::seedDemoData()`.
  - Writes `storage/installed.lock`.
