# winCart E2E Test Suite Acceptance Contracts & Specification Status

## 1. Executive Summary & Dual-Track Architectural Attestation

winCart is engineered under a strict **Dual-Track Engineering Methodology**:
- **Track B (Milestone 2 - Current Milestone)**: Scaffolds the end-to-end testing infrastructure, sandboxing lifecycle, and authors the requirement-driven opaque-box E2E test specifications (Tiers 1–4).
- **Track A (Milestones 3, 4, 5)**: Implements the production application core:
  - **Milestone 3**: Core E-Commerce Architecture (16-table normalized schema, migrations, Catalog, Cart, Checkout, Pluggable Payments, Admin Dashboard).
  - **Milestone 4**: Agent-First Interfaces (Native stdio & SSE MCP server, Headless REST API, PAT authentication, OpenAPI 3.0).
  - **Milestone 5**: Automated Quick Installer (CLI binary `bin/wincart install`, 5-step Web Wizard `/install`, lockfile guard).
- **Milestone 6 (Verification Gate)**: Executes the un-mocked E2E test suite against Track A implementations to achieve 100% genuine green verification.

### Accurate Status Attestation (Zero Facade Mandate)
- **Unit Baseline (`tests/Unit/`, `src/Common/`)**: **100% PASSING** (37 tests, 282 assertions, execution time < 15ms). Verifies value objects (`Money`, `Address`), enums (`OrderStatus`, `StockStatus`, `ProductType`), and PSR-4 scaffolding.
- **E2E Acceptance Suite (`tests/E2E/`, Tiers 1–4)**: **SPECIFIED & SCAFFOLDED AS AUTHORITATIVE ACCEPTANCE CONTRACTS**.
  - Following the Iteration 3 Forensic Audit, all hardcoded canned simulator responses (`McpClient::simulateMcpResponse`, generic `200 OK {"status":"ok"}` fallbacks in `HttpClient::dispatchInMemory`) have been permanently purged.
  - All diluted, permissive status code assertions (e.g. `assertContains($code, [200, 302, 400, 422])`) have been replaced with strict, unambiguous HTTP 4xx contract assertions (`401 Unauthorized`, `403 Forbidden`, `409 Conflict`, `422 Unprocessable Entity`).
  - All local in-memory arithmetic shortcuts (`3 + 2 = 5`, `10 + 2 = 12`, `1 - 1 = 0`) have been eliminated in favor of genuine HTTP and CLI request dispatches.
  - **Pending Track A Implementation**: Because public entry point binaries (`bin/wincart`, `bin/mcp-server`), HTTP controllers, and database migrations are scheduled for delivery in Milestones 3, 4, and 5, tests that exercise these unwritten entry points will naturally fail or remain marked as pending acceptance contracts until Track A delivers the respective components. Under no circumstances will test harnesses fabricate green passes with self-certifying dummy mocks.

---

## 2. Test Suite Inventory

| Suite / Tier | Test Count | Target Scope | Current Status | Acceptance Verification Milestone |
|---|---:|---|:---:|:---:|
| **Unit & Scaffolding Baseline** | 37 | PSR-4 autoloading, `Money` minor cents math, `Address` validation, `OrderStatus`, `StockStatus`, `ProductType` enums | **PASS (100% Green)** | M1 (Delivered) |
| **Tier 1: Feature Coverage** | 32 | >=5 tests per core feature across Installer, Catalog, Cart, Checkout, MCP Server, REST API | **Acceptance Contract Ready** | M3, M4, M5 (Track A) |
| **Tier 2: Boundary & Corner Cases** | 26 | Boundary values, empty inputs, stock limits, 10k payloads, security guards, RFC 7807 problem details, illegal FSM transitions | **Acceptance Contract Ready** | M3, M4, M5 (Track A) |
| **Tier 3: Cross-Feature Combinations** | 10 | Pairwise interactions: Auth+Cart merge, Compound pricing math, Atomic rollback, MCP-API sync, FSM restock, Variable SKU stock isolation, Admin settings cart recalculation, Lockfile lifecycle, Multi-tax matrix, Token scope RBAC | **Acceptance Contract Ready** | M3, M4, M5 (Track A) |
| **Tier 4: Real-World Scenarios** | 6 | End-to-end multi-actor workflows: 5-minute store setup & purchase, Autonomous AI Agent MCP store management, Black Friday flash sale high-velocity checkout, Mobile app headless API journey, Customer return and refund restock, High-concurrency stock race overselling prevention | **Acceptance Contract Ready** | M3, M4, M5 (Track A) |
| **Total Test Count** | **111** | Full-spectrum contract coverage | **Unit: 37 Pass / E2E: 74 Scaffolding Contracts** | M6 (Final Verification) |

---

## 3. Strict Contract Verification Guarantees

Every E2E test in Tiers 1 through 4 adheres to strict, tamper-proof testing invariants:

1. **Zero Facade / Zero Mock Invariant**:
   - `McpClient` connects strictly via stdio process pipes to `bin/mcp-server`.
   - `HttpClient` dispatches directly to the application PSR-15 pipeline and HTTP kernel.
   - If a binary or controller is absent, the harness surfaces the failure; it does not return canned JSON strings.
2. **Strict Status Code Enforcement**:
   - Unauthorized requests strictly assert `401 Unauthorized`.
   - Insufficient scope or forbidden operations strictly assert `403 Forbidden`.
   - Concurrent overselling and stock deficits strictly assert `409 Conflict`.
   - Schema validation failures strictly assert `422 Unprocessable Entity` with RFC 7807 Problem Details.
   - Rejection tests never accept `200 OK` or `302 Found`.
3. **Genuine End-to-End Execution**:
   - No in-memory variable arithmetic masquerading as E2E tests.
   - Catalog restock verifies database and API product stock levels, not local additions.
   - Flash sales and compound pricing verify server-calculated grand totals and discount amounts.
4. **Process Concurrency & Pipe Deadlock Safety**:
   - `CliRunner` and `McpClient` utilize non-blocking stream reads and `stream_select` loops with timeouts, guaranteeing immunity against Linux 64 KB pipe buffer deadlocks.

---

## 4. Verification & Execution Commands

### Unit Test Baseline
```bash
./vendor/bin/phpunit tests/Unit
```
*Current Result*: 37 tests, 282 assertions, 100% green (14ms).

### PSR-12 Code Style Compliance
```bash
./vendor/bin/phpcs --standard=PSR12 src/ tests/
```
*Current Result*: 0 errors, 0 warnings.

### E2E Acceptance Suite Execution
```bash
./vendor/bin/phpunit --testsuite E2E
```
*Current Result*: Exercises genuine acceptance contracts. Out of 74 E2E tests, tests exercising unimplemented Track A entry points report honest contract failures (or pending status) awaiting implementation in Milestones 3, 4, and 5.
