<?php

declare(strict_types=1);

namespace WinCart\Database;

use PDO;

final class TestFixtureSeeder
{
    public static function seed(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Customers and Auth tokens
        $tokens = [
            [
                'id' => 1,
                'email' => 'admin@example.com',
                'role' => 'admin',
                'token' => 'pat_admin_scope_token',
                'scopes' => 'admin,read,write',
            ],
            [
                'id' => 2,
                'email' => 'jane.doe@example.com',
                'role' => 'customer',
                'token' => 'pat_jane_doe_session',
                'scopes' => 'read,write',
            ],
            [
                'id' => 3,
                'email' => 'tester@example.com',
                'role' => 'customer',
                'token' => 'pat_valid_test_token_123',
                'scopes' => 'read,write',
            ],
            [
                'id' => 4,
                'email' => 'readonly@example.com',
                'role' => 'customer',
                'token' => 'pat_read_only_token_abc',
                'scopes' => 'read',
            ],
            [
                'id' => 5,
                'email' => 'productreader@example.com',
                'role' => 'customer',
                'token' => 'pat_read_products_only',
                'scopes' => 'read',
            ],
            [
                'id' => 6,
                'email' => 'mobile@example.com',
                'role' => 'customer',
                'token' => 'pat_mobile_app_token_99',
                'scopes' => 'read,write',
            ],
            [
                'id' => 7,
                'email' => 'agent@example.com',
                'role' => 'admin',
                'token' => 'pat_test_token',
                'scopes' => 'admin,read,write',
                'raw' => false,
            ],
            [
                'id' => 8,
                'email' => 'legacy@example.com',
                'role' => 'customer',
                'token' => 'pat_legacy_raw_unhashed_token',
                'scopes' => 'read,write',
                'raw' => true,
            ],
        ];

        $custSql = 'INSERT OR IGNORE INTO customers (
            id, email, password_hash, first_name, last_name, role, pat_token_hash,
            pat_scopes, is_active, created_at, updated_at
        ) VALUES (
            :id, :email, :pass, :fname, :lname, :role, :token, :scopes, 1, :now, :now
        )';
        $custStmt = $pdo->prepare($custSql);

        $passwordHash = password_hash('Password123!', PASSWORD_BCRYPT);
        foreach ($tokens as $t) {
            $tokenValue = !empty($t['raw']) ? $t['token'] : hash('sha256', $t['token']);
            $custStmt->execute([
                ':id' => $t['id'],
                ':email' => $t['email'],
                ':pass' => $passwordHash,
                ':fname' => 'Test',
                ':lname' => 'User',
                ':role' => $t['role'],
                ':token' => $tokenValue,
                ':scopes' => $t['scopes'],
                ':now' => $now,
            ]);
        }

        // 2. Categories
        $categories = [
            ['id' => 1, 'name' => 'Laptops', 'slug' => 'laptops'],
            ['id' => 2, 'name' => 'Amplifiers', 'slug' => 'amplifiers'],
            ['id' => 3, 'name' => 'Turntables', 'slug' => 'turntables'],
            ['id' => 4, 'name' => 'Chairs', 'slug' => 'chairs'],
        ];

        $catSql = 'INSERT OR IGNORE INTO categories (id, name, slug, is_active, created_at, updated_at)
                   VALUES (:id, :name, :slug, 1, :now, :now)';
        $catStmt = $pdo->prepare($catSql);
        foreach ($categories as $c) {
            $catStmt->execute([':id' => $c['id'], ':name' => $c['name'], ':slug' => $c['slug'], ':now' => $now]);
        }

        // 3. Products
        $products = [
            [
                'id' => 1,
                'name' => 'Ergonomic Chair',
                'slug' => 'ergonomic-chair',
                'sku' => 'PROD-SKU-99',
                'type' => 'simple',
                'price' => 2500,
                'stock' => 10,
                'status' => 'instock',
            ],
            [
                'id' => 2,
                'name' => 'Studio Laptop',
                'slug' => 'studio-laptop',
                'sku' => 'LAP-01',
                'type' => 'simple',
                'price' => 99900,
                'stock' => 5,
                'status' => 'instock',
            ],
            [
                'id' => 3,
                'name' => 'Vintage Turntable',
                'slug' => 'vintage-turntable',
                'sku' => 'TT-01',
                'type' => 'simple',
                'price' => 34900,
                'stock' => 10,
                'status' => 'instock',
            ],
            [
                'id' => 4,
                'name' => 'HiFi Amplifier',
                'slug' => 'hifi-amplifier',
                'sku' => 'AMP-01',
                'type' => 'simple',
                'price' => 49900,
                'stock' => 3,
                'status' => 'instock',
            ],
            [
                'id' => 5,
                'name' => 'Mobile Amp',
                'slug' => 'mobile-amp',
                'sku' => 'AMP-02',
                'type' => 'simple',
                'price' => 19900,
                'stock' => 10,
                'status' => 'instock',
            ],
            [
                'id' => 10,
                'name' => 'Studio Headphones',
                'slug' => 'studio-headphones',
                'sku' => 'HP-10',
                'type' => 'simple',
                'price' => 15000,
                'stock' => 50,
                'status' => 'instock',
            ],
            [
                'id' => 11,
                'name' => 'Headphone Stand',
                'slug' => 'headphone-stand',
                'sku' => 'STD-11',
                'type' => 'simple',
                'price' => 3500,
                'stock' => 50,
                'status' => 'instock',
            ],
            [
                'id' => 12,
                'name' => 'Audio Cables',
                'slug' => 'audio-cables',
                'sku' => 'CBL-12',
                'type' => 'simple',
                'price' => 1500,
                'stock' => 50,
                'status' => 'instock',
            ],
            [
                'id' => 101,
                'name' => 'Product 101',
                'slug' => 'product-101',
                'sku' => 'P-101',
                'type' => 'simple',
                'price' => 2500,
                'stock' => 20,
                'status' => 'instock',
            ],
            [
                'id' => 102,
                'name' => 'Product 102',
                'slug' => 'product-102',
                'sku' => 'P-102',
                'type' => 'simple',
                'price' => 1000,
                'stock' => 20,
                'status' => 'instock',
            ],
            [
                'id' => 201,
                'name' => 'Product 201',
                'slug' => 'product-201',
                'sku' => 'P-201',
                'type' => 'simple',
                'price' => 1999,
                'stock' => 20,
                'status' => 'instock',
            ],
            [
                'id' => 202,
                'name' => 'Product 202',
                'slug' => 'product-202',
                'sku' => 'P-202',
                'type' => 'simple',
                'price' => 495,
                'stock' => 20,
                'status' => 'instock',
            ],
            [
                'id' => 301,
                'name' => 'Product 301',
                'slug' => 'product-301',
                'sku' => 'P-301',
                'type' => 'simple',
                'price' => 1000,
                'stock' => 10,
                'status' => 'instock',
            ],
            [
                'id' => 303,
                'name' => 'Product 303',
                'slug' => 'product-303',
                'sku' => 'P-303',
                'type' => 'simple',
                'price' => 1000,
                'stock' => 0,
                'status' => 'outofstock',
            ],
            [
                'id' => 501,
                'name' => 'Product 501',
                'slug' => 'product-501',
                'sku' => 'P-501',
                'type' => 'simple',
                'price' => 2000,
                'stock' => 3,
                'status' => 'instock',
            ],
            [
                'id' => 600,
                'name' => 'Variable Product 600',
                'slug' => 'product-600',
                'sku' => 'P-600',
                'type' => 'variable',
                'price' => 3000,
                'stock' => 3,
                'status' => 'instock',
            ],
            [
                'id' => 701,
                'name' => 'Product 701',
                'slug' => 'product-701',
                'sku' => 'P-701',
                'type' => 'simple',
                'price' => 10000,
                'stock' => 10,
                'status' => 'instock',
            ],
            [
                'id' => 901,
                'name' => 'Product 901',
                'slug' => 'product-901',
                'sku' => 'P-901',
                'type' => 'simple',
                'price' => 10000,
                'stock' => 1,
                'status' => 'instock',
            ],
        ];

        $prodSql = 'INSERT OR IGNORE INTO products (
            id, name, slug, sku, product_type, price, stock_quantity, stock_status,
            manage_stock, is_active, created_at, updated_at
        ) VALUES (
            :id, :name, :slug, :sku, :type, :price, :stock, :status, 1, 1, :now, :now
        )';
        $prodStmt = $pdo->prepare($prodSql);
        foreach ($products as $p) {
            $prodStmt->execute([
                ':id' => $p['id'],
                ':name' => $p['name'],
                ':slug' => $p['slug'],
                ':sku' => $p['sku'],
                ':type' => $p['type'],
                ':price' => $p['price'],
                ':stock' => $p['stock'],
                ':status' => $p['status'],
                ':now' => $now,
            ]);
        }

        // 4. Product Categories Mapping
        $prodCats = [
            ['pid' => 1, 'cid' => 4],
            ['pid' => 2, 'cid' => 1],
            ['pid' => 3, 'cid' => 3],
            ['pid' => 4, 'cid' => 2],
            ['pid' => 5, 'cid' => 2],
        ];
        $pcSql = 'INSERT OR IGNORE INTO product_categories (product_id, category_id, is_primary)
                  VALUES (:pid, :cid, 1)';
        $pcStmt = $pdo->prepare($pcSql);
        foreach ($prodCats as $pc) {
            $pcStmt->execute([':pid' => $pc['pid'], ':cid' => $pc['cid']]);
        }

        // 5. Product Variations for 600
        $variations = [
            [
                'id' => 601,
                'pid' => 600,
                'sku' => 'VAR-601',
                'price' => 3000,
                'stock' => 3,
                'status' => 'instock',
                'attrs' => '{"color":"black","size":"large"}',
            ],
            [
                'id' => 602,
                'pid' => 600,
                'sku' => 'VAR-602',
                'price' => 3000,
                'stock' => 0,
                'status' => 'outofstock',
                'attrs' => '{"color":"white","size":"small"}',
            ],
        ];
        $varSql = 'INSERT OR IGNORE INTO product_variations (
            id, product_id, sku, price, stock_quantity, stock_status, attributes_json,
            is_active, created_at, updated_at
        ) VALUES (
            :id, :pid, :sku, :price, :stock, :status, :attrs, 1, :now, :now
        )';
        $varStmt = $pdo->prepare($varSql);
        foreach ($variations as $v) {
            $varStmt->execute([
                ':id' => $v['id'],
                ':pid' => $v['pid'],
                ':sku' => $v['sku'],
                ':price' => $v['price'],
                ':stock' => $v['stock'],
                ':status' => $v['status'],
                ':attrs' => $v['attrs'],
                ':now' => $now,
            ]);
        }

        // 6. Tax Zones
        $taxZones = [
            [
                'country' => 'US', 'state' => 'WA', 'name' => 'Washington',
                'rate' => 0.1025, 'flat' => 750, 'free' => 15000,
            ],
            [
                'country' => 'US', 'state' => 'CA', 'name' => 'California',
                'rate' => 0.0850, 'flat' => 750, 'free' => 15000,
            ],
            [
                'country' => 'US', 'state' => 'NY', 'name' => 'New York',
                'rate' => 0.08875, 'flat' => 750, 'free' => 15000,
            ],
            [
                'country' => 'US', 'state' => 'OR', 'name' => 'Oregon',
                'rate' => 0.0000, 'flat' => 750, 'free' => 15000,
            ],
            [
                'country' => 'US', 'state' => 'IL', 'name' => 'Illinois',
                'rate' => 0.0825, 'flat' => 750, 'free' => 15000,
            ],
        ];
        $tzSql = 'INSERT OR IGNORE INTO tax_zones (
            country, state, name, rate, flat_shipping_rate, free_shipping_threshold,
            is_active, created_at
        ) VALUES (
            :country, :state, :name, :rate, :flat, :free, 1, :now
        )';
        $tzStmt = $pdo->prepare($tzSql);
        foreach ($taxZones as $tz) {
            $tzStmt->execute([
                ':country' => $tz['country'],
                ':state' => $tz['state'],
                ':name' => $tz['name'],
                ':rate' => $tz['rate'],
                ':flat' => $tz['flat'],
                ':free' => $tz['free'],
                ':now' => $now,
            ]);
        }

        // 7. Coupons
        $coupons = [
            ['code' => 'DISCOUNT15', 'type' => 'percentage', 'value' => 15, 'min' => 0, 'expires' => null],
            ['code' => 'SAVE15', 'type' => 'percentage', 'value' => 15, 'min' => 0, 'expires' => null],
            ['code' => 'BF30', 'type' => 'percentage', 'value' => 30, 'min' => 0, 'expires' => null],
            [
                'code' => 'EXPIRED_2020',
                'type' => 'percentage',
                'value' => 20,
                'min' => 0,
                'expires' => '2020-01-01 00:00:00',
            ],
        ];
        $cpSql = 'INSERT OR IGNORE INTO coupons (
            code, discount_type, discount_value, min_subtotal, expires_at, is_active, created_at
        ) VALUES (
            :code, :type, :value, :min, :expires, 1, :now
        )';
        $cpStmt = $pdo->prepare($cpSql);
        foreach ($coupons as $cp) {
            $cpStmt->execute([
                ':code' => $cp['code'],
                ':type' => $cp['type'],
                ':value' => $cp['value'],
                ':min' => $cp['min'],
                ':expires' => $cp['expires'],
                ':now' => $now,
            ]);
        }

        // 8. Orders for testing
        $orders = [
            ['id' => 1, 'num' => 'WC-001', 'status' => 'completed', 'sub' => 10000, 'total' => 10000],
            ['id' => 2, 'num' => 'WC-002', 'status' => 'refunded', 'sub' => 10000, 'total' => 10000],
            ['id' => 3, 'num' => 'WC-003', 'status' => 'pending', 'sub' => 10000, 'total' => 10000],
            ['id' => 4, 'num' => 'WC-004', 'status' => 'cancelled', 'sub' => 10000, 'total' => 10000],
            ['id' => 2001, 'num' => 'WC-2001', 'status' => 'pending', 'sub' => 5000, 'total' => 5000],
            ['id' => 3001, 'num' => 'WC-3001', 'status' => 'completed', 'sub' => 20000, 'total' => 20000],
            ['id' => 5001, 'num' => 'WC-5001', 'status' => 'processing', 'sub' => 4000, 'total' => 4000],
        ];

        $ordSql = 'INSERT OR IGNORE INTO orders (
            id, order_number, customer_id, status, currency, subtotal, discount_amount, tax_amount,
            shipping_amount, grand_total, billing_first_name, billing_last_name, billing_address_1,
            billing_city, billing_state, billing_postal_code, billing_country, shipping_first_name,
            shipping_last_name, shipping_address_1, shipping_city, shipping_state, shipping_postal_code,
            shipping_country, payment_method, created_at, updated_at
        ) VALUES (
            :id, :num, 2, :status, "USD", :sub, 0, 0,
            0, :total, "Jane", "Doe", "123 Main St",
            "Seattle", "WA", "98101", "US", "Jane",
            "Doe", "123 Main St", "Seattle", "WA", "98101",
            "US", "mock", :now, :now
        )';
        $ordStmt = $pdo->prepare($ordSql);
        foreach ($orders as $o) {
            $ordStmt->execute([
                ':id' => $o['id'],
                ':num' => $o['num'],
                ':status' => $o['status'],
                ':sub' => $o['sub'],
                ':total' => $o['total'],
                ':now' => $now,
            ]);
        }

        // Line items for order 3001 (2x product 701) and order 5001 (2x product 501)
        $itemSql = 'INSERT OR IGNORE INTO order_items (
            order_id, product_id, product_name, product_sku, unit_price, quantity, line_subtotal, created_at
        ) VALUES (
            :oid, :pid, :name, :sku, :price, :qty, :sub, :now
        )';
        $itemStmt = $pdo->prepare($itemSql);
        $itemStmt->execute([
            ':oid' => 3001,
            ':pid' => 701,
            ':name' => 'Product 701',
            ':sku' => 'P-701',
            ':price' => 10000,
            ':qty' => 2,
            ':sub' => 20000,
            ':now' => $now,
        ]);
        $itemStmt->execute([
            ':oid' => 5001,
            ':pid' => 501,
            ':name' => 'Product 501',
            ':sku' => 'P-501',
            ':price' => 2000,
            ':qty' => 2,
            ':sub' => 4000,
            ':now' => $now,
        ]);

        // Customer 2 (Jane Doe) existing cart in DB (contains 2x product 102)
        $cartSql = 'INSERT OR IGNORE INTO carts (id, token, customer_id, created_at, updated_at)
                    VALUES (200, "cart_jane_doe", 2, :now, :now)';
        $pdo->prepare($cartSql)->execute([':now' => $now]);

        $cItemSql = 'INSERT OR IGNORE INTO cart_items (cart_id, product_id, quantity, created_at, updated_at)
                     VALUES (200, 102, 2, :now, :now)';
        $pdo->prepare($cItemSql)->execute([':now' => $now]);
    }
}
