<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>winCart Admin Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; }
        .nav { background: #0f172a; color: #ffffff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .nav a { color: #cbd5e1; text-decoration: none; margin-left: 1.5rem; }
        .nav a:hover { color: #ffffff; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: #ffffff; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { margin: 0 0 0.5rem 0; font-size: 0.875rem; color: #64748b; text-transform: uppercase; }
        .card .value { font-size: 1.875rem; font-weight: 700; color: #0f172a; }
        .alert-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; }
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
            <a href="/" target="_blank">Storefront</a>
        </div>
    </nav>
    <div class="container">
        <h1>Dashboard Overview</h1>
        <?php if (!empty($metrics['low_stock_products'])): ?>
            <div class="alert-warning">
                <strong>Attention:</strong> <?= (int)$metrics['low_stock_products'] ?> product(s) have low stock levels.
            </div>
        <?php endif; ?>
        <div class="grid">
            <div class="card">
                <h3>Total Orders</h3>
                <div class="value"><?= (int)($metrics['total_orders'] ?? 0) ?></div>
            </div>
            <div class="card">
                <h3>Total Revenue</h3>
                <div class="value"><?= htmlspecialchars($metrics['formatted_revenue'] ?? '$0.00', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="card">
                <h3>Pending Orders</h3>
                <div class="value"><?= (int)($metrics['pending_orders'] ?? 0) ?></div>
            </div>
            <div class="card">
                <h3>Completed Orders</h3>
                <div class="value"><?= (int)($metrics['completed_orders'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</body>
</html>
