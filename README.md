# winCart

> Modern, High-Performance Agent-First PHP E-Commerce Platform

winCart is a production-grade modern PHP 8.x e-commerce platform engineered to compete directly with WooCommerce. It eliminates legacy architectural bottlenecks (such as monolithic EAV postmeta tables) with a clean, normalized relational schema, full strict typing (`declare(strict_types=1);`), native Model Context Protocol (MCP) server integration, and a 5-minute automated installer.

---

## Key Features

- **Blazing Performance & Clean Architecture**: Pure PHP 8.2+ with PSR-4 autoloading (`WinCart\`), PSR-7/17 HTTP messaging, and zero legacy Zend Framework v1 bloat. Outperforms traditional monolithic e-commerce engines by 10x–50x.
- **Dual-Engine Relational Database**: Normalized 16-table schema supporting zero-config SQLite 3 (for rapid local development, instantaneous automated tests, and edge setups) and MySQL 8.0+ / MariaDB (for enterprise production scale).
- **Domain-Driven E-Commerce Core**:
  - **Catalog**: Hierarchical categories, simple and variable products, atomic inventory decrements, and attributes.
  - **Cart**: Persistent session and database cart with auto-merge on login, dynamic coupon engine, and shipping zones.
  - **Checkout & Orders**: Streamlined 1-page checkout, immutable snapshot line items, and pluggable payment gateways.
  - **Admin Dashboard**: Lightweight, responsive administrative control panel with real-time KPI metrics.
- **Agent-First Interfaces**:
  - Native Model Context Protocol (MCP) server supporting `stdio` (for Claude Desktop, Cursor, Gemini CLI) and Server-Sent Events (`SSE`).
  - Headless REST API (`/api/v1`) with Personal Access Token (PAT) authentication, granular permission scopes, and OpenAPI 3.0 schemas.
- **5-Minute Quick Installer**:
  - Interactive CLI command (`php bin/wincart install`).
  - 5-step guided Web Wizard (`/install`) with environment verification, database configuration, admin provisioning, and demo catalog seeding.
  - Post-install cryptographic lockfile guard (`storage/installed.lock`).

---

## Requirements

- **PHP**: `^8.2` or `^8.3` or `^8.4`
- **Extensions**: `ext-pdo`, `ext-json`, `ext-mbstring`, `ext-openssl`, and `ext-pdo_sqlite` (or `ext-pdo_mysql`)
- **Composer**: `2.2+`
- **Database**: SQLite 3 or MySQL 8.0+ / MariaDB 10.5+

---

## Quickstart

### 1. Installation & Dependency Setup
```bash
git clone git@github.com:rbaindourov/wincart.git
cd wincart
composer install
```

### 2. Run the Automated Installer
To quickly initialize winCart using the default zero-config SQLite database:
```bash
php bin/wincart install \
  --db=sqlite \
  --admin-email=admin@wincart.local \
  --admin-password=ChangeMe123! \
  --seed-demo=true
```

### 3. Launch Development Server
```bash
php -S 127.0.0.1:8000 -t public
```

- **Storefront**: [http://127.0.0.1:8000](http://127.0.0.1:8000)
- **Admin Dashboard**: [http://127.0.0.1:8000/admin](http://127.0.0.1:8000/admin)
- **REST API Docs**: [http://127.0.0.1:8000/api/v1/openapi.json](http://127.0.0.1:8000/api/v1/openapi.json)

---

## Agent / MCP Server Integration

winCart provides out-of-the-box MCP integration for autonomous agents. Connect your MCP client (such as Claude Desktop or custom agents) via standard I/O:

```json
{
  "mcpServers": {
    "wincart": {
      "command": "php",
      "args": ["/path/to/wincart/bin/mcp-server"],
      "env": {
        "WINCART_API_KEY": "wc_live_your_pat_token_here"
      }
    }
  }
}
```

Exposed MCP tools:
- `search_products`, `get_product`, `create_product`, `update_product`, `delete_product`
- `list_orders`, `get_order`, `update_order_status`
- `get_store_metrics`

---

## Testing & Quality Assurance

Run the comprehensive test suite with PHPUnit:
```bash
composer test
```

Run code style inspection:
```bash
composer check-style
```

---

## Directory Overview

```
wincart/
├── bin/                 # CLI entrypoints (wincart, mcp-server)
├── config/              # Application & service configurations
├── database/            # Idempotent migrations and seeders
├── public/              # Web root (index.php, static assets, uploads)
├── src/                 # Domain-driven PSR-4 application sources
├── storage/             # Runtime caches, logs, and SQLite database
├── templates/           # Server-rendered storefront, admin, and installer templates
└── tests/               # Unit, Feature, and End-to-End test suites
```

---

## License

This software is released under the **MIT License**. Copyright (c) 2026 Robert Baindourov <rbaindourov@gmail.com>.
