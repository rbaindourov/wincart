# E2E Test Infrastructure: winCart

## 1. Test Philosophy
- **Opaque-Box Requirement-Driven**: Tests exercise winCart purely through public entry points:
  - CLI management binary (`bin/wincart`) via subprocess execution
  - Native Model Context Protocol (MCP) server (`bin/mcp-server`) stdio JSON-RPC 2.0 lines and HTTP SSE
  - Headless REST API (`/api/v1/*`) with Bearer Personal Access Tokens (PAT) and RFC 7807 Problem Details
  - Web UI endpoints: Web Installer (`/install`), Storefront (`/`, `/cart`, `/checkout`), Admin (`/admin/*`)
- **Zero Coupling to Internals**: Tests have zero dependencies on private implementation classes, service method signatures, or direct database table manipulations.
- **Dual-Track Verification**: The E2E test suite acts as the authoritative acceptance oracle for implementation Milestones M3 (Core E-Commerce), M4 (Agent Interfaces), and M5 (Automated Installer).
- **Ephemeral Sandbox Isolation**: Every test run operates in an isolated environment with dynamic SQLite database files (`storage/test_e2e_<uuid>.sqlite`), isolated lockfiles (`INSTALL_LOCK_PATH`), and automated teardown.

## 2. Public Entry Point Invariants
1. **CLI Binary**: `bin/wincart <command>` executed via process-isolated runner (`proc_open`).
2. **MCP Server**: Line-delimited JSON-RPC 2.0 over standard I/O conforming to MCP specification `2024-11-05` and HTTP SSE (`/api/mcp/sse`).
3. **Headless REST API**: `/api/v1/*` endpoints authenticated via Bearer PAT tokens, returning RFC 7807 Problem Details and OpenAPI 3.0 schemas.
4. **Web UI**: Web Installer (`/install`), Storefront (`/`, `/product/*`, `/cart`, `/checkout`), and Admin Control Panel (`/admin/*`).

## 3. Feature Inventory & Test Coverage Matrix

| # | Feature Area | Requirement | Tier 1 (Feature) | Tier 2 (Boundary) | Tier 3 (Cross-Feature) | Tier 4 (Real-World) |
|---|---|---|:---:|:---:|:---:|:---:|
| 1 | Automated Installer (CLI & Web) | R4 | 5 | 5 | ✓ | ✓ (Scenario 1) |
| 2 | Product Catalog & Category Hierarchy | R2 | 5 | 5 | ✓ | ✓ (Scenario 1, 4, 5) |
| 3 | Persistent Cart & Coupon Engine | R2 | 5 | 5 | ✓ | ✓ (Scenario 3, 4) |
| 4 | Checkout & Order State Machine | R2 | 5 | 6 | ✓ | ✓ (Scenario 1, 3, 5, 6) |
| 5 | Pluggable Payments (MockGateway) | R2 | 5 | 5 | ✓ | ✓ (Scenario 1, 3, 4) |
| 6 | Administrative Dashboard & Metrics | R2 | 5 | 5 | ✓ | ✓ (Scenario 1, 2) |
| 7 | Native MCP Server (stdio & SSE) | R3 | 7 | 5 | ✓ | ✓ (Scenario 2) |
| 8 | Headless REST API (`/api/v1`) & Auth | R3 | 5 | 5 | ✓ | ✓ (Scenario 4) |
| **Total** | | | **32** | **26** | **10** | **6** |

## 4. Test Architecture & Runner Invocation

### Runner Commands
- **Run Complete E2E Suite**:
  ```bash
  composer test:e2e
  # or directly:
  ./vendor/bin/phpunit --testsuite E2E
  ```
- **Run Specific Test Tiers**:
  ```bash
  ./vendor/bin/phpunit tests/E2E/Tier1_FeatureCoverage
  ./vendor/bin/phpunit tests/E2E/Tier2_BoundaryCornerCases
  ./vendor/bin/phpunit tests/E2E/Tier3_Combinatorial
  ./vendor/bin/phpunit tests/E2E/Tier4_RealWorldScenarios
  ```
- **Run Unit Test Baseline**:
  ```bash
  composer test
  # or directly:
  ./vendor/bin/phpunit
  ```

### Directory Structure
```
tests/E2E/
├── Support/
│   ├── OpaqueBoxTestCase.php       # Base test class with environment isolation & teardown
│   ├── TestEnvironment.php         # Ephemeral SQLite db, lockfile, and directory management
│   ├── HttpClient.php              # In-memory PSR-15 dispatcher and cURL client
│   ├── CliRunner.php               # Process-isolated runner for bin/wincart
│   └── McpClient.php               # Stdio JSON-RPC 2.0 client for bin/mcp-server
├── Tier1_FeatureCoverage/
│   ├── InstallerFeatureTest.php
│   ├── CatalogFeatureTest.php
│   ├── CartFeatureTest.php
│   ├── CheckoutFeatureTest.php
│   ├── McpServerFeatureTest.php
│   └── RestApiFeatureTest.php
├── Tier2_BoundaryCornerCases/
│   ├── EmptyInputBoundaryTest.php
│   ├── BoundaryLimitsTest.php
│   ├── ExtremeValuesTest.php
│   ├── SecurityGuardsTest.php
│   └── IllegalStateTransitionsTest.php
├── Tier3_Combinatorial/
│   └── CrossFeatureCombinationTest.php
└── Tier4_RealWorldScenarios/
    └── RealWorldApplicationScenariosTest.php
```

## 5. Real-World Application Scenarios (Tier 4)

| # | Scenario | Features Exercised | Target Flow |
|---|---|---|---|
| 1 | Complete 5-Minute Store Setup & Purchase | Installer CLI, Catalog, Cart, Checkout, Mock Payment, Admin Dashboard | `bin/wincart install --seed` -> Browse `/` -> Add to Cart -> Checkout -> Admin verifies order #1001 |
| 2 | Autonomous AI Agent Store Management | MCP stdio Server, Tools (`get_store_metrics`, `update_product`, `list_orders`, `update_order_status`) | Connect MCP -> Query metrics -> Detect low stock -> Restock item -> Fulfill pending order with tracking # |
| 3 | Black Friday Flash Sale & Promo Code | Cart, Coupon Engine, Dynamic Tax, Multi-Item Checkout, Atomic Stock Decrement | High-volume cart -> Apply promo coupon -> Calculate state tax -> Free shipping tier -> Checkout -> Stock decrements |
| 4 | Headless Mobile App Checkout | Headless REST API (`/api/v1`), Bearer PAT Auth, Cart API, Checkout API | Token auth -> OpenAPI schema -> Browse catalog -> Init cart -> Apply coupon -> Checkout -> Receive order receipt JSON |
| 5 | Customer Return & Order Refund Workflow | Catalog Attributes, Order FSM, Terminal Guards, Inventory Restock | Completed order -> Transition to refunded -> Verify inventory restock -> Verify immutable audit log |
| 6 | High-Concurrency Stock Race Prevention | Inventory Engine, Atomic Stock Validation, Row Locks, Rollback | Simultaneous checkout of last available unit -> Single order succeeds -> Graceful 409 Conflict rollback for second shopper |
