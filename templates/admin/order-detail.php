<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>winCart Admin - Order Details</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; }
        .nav { background: #0f172a; color: #ffffff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .nav a { color: #cbd5e1; text-decoration: none; margin-left: 1.5rem; }
        .container { max-width: 960px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #ffffff; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        .badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <nav class="nav">
        <h2>winCart Admin</h2>
        <div>
            <a href="/admin">Dashboard</a>
            <a href="/admin/orders">Orders</a>
            <a href="/admin/products">Products</a>
            <a href="/admin/settings">Settings</a>
        </div>
    </nav>
    <div class="container">
        <h1>Order <?= htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="card">
            <h3>Status: <span class="badge"><?= htmlspecialchars($order['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></h3>
            <p><strong>Grand Total:</strong> $<?= number_format(((int)($order['grand_total'] ?? 0)) / 100, 2) ?></p>
            <p><strong>Payment Method:</strong> <?= htmlspecialchars($order['payment_method'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Customer:</strong> <?= htmlspecialchars(($order['billing_first_name'] ?? '') . ' ' . ($order['billing_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            
            <h4>Line Items</h4>
            <table>
                <thead>
                    <tr><th>Product</th><th>SKU</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['product_sku'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>$<?= number_format(((int)$item['unit_price']) / 100, 2) ?></td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td>$<?= number_format(((int)$item['line_subtotal']) / 100, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
