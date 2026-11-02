<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>winCart Admin Orders</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; }
        .nav { background: #0f172a; color: #ffffff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .nav a { color: #cbd5e1; text-decoration: none; margin-left: 1.5rem; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; color: #475569; }
        .badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
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
        <h1>Orders Management</h1>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)) : ?>
                    <tr><td colspan="6">No orders found.</td></tr>
                <?php else : ?>
                    <?php foreach ($orders as $o) : ?>
                        <tr>
                            <td><a href="/admin/orders/<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['order_number'], ENT_QUOTES, 'UTF-8') ?></a></td>
                            <td><?= htmlspecialchars(($o['billing_first_name'] ?? '') . ' ' . ($o['billing_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>$<?= number_format(((int)$o['grand_total']) / 100, 2) ?></td>
                            <td><?= htmlspecialchars(substr((string)$o['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a href="/admin/orders/<?= (int)$o['id'] ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
