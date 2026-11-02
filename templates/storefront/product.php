<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?> - winCart</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fafafa; color: #171717; margin: 0; }
        header { background: #171717; color: #fff; padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #fff; text-decoration: none; font-weight: 600; margin-left: 1.5rem; }
        .container { max-width: 800px; margin: 3rem auto; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 2rem; }
        .price { font-size: 1.75rem; font-weight: 700; color: #059669; margin: 1rem 0; }
        .sku { color: #737373; font-family: monospace; }
        .stock { font-weight: 600; color: #16a34a; }
        .btn { background: #2563eb; color: #fff; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <header>
        <h2>winCart Store</h2>
        <nav><a href="/">Home</a><a href="/admin">Admin</a></nav>
    </header>
    <main class="container">
        <h1><?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="sku">SKU: <?= htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        <p class="price">$<?= number_format(((int)($product['price'] ?? 0)) / 100, 2) ?></p>
        <p class="stock">Availability: <?= htmlspecialchars($product['stock_status'] ?? 'in_stock', ENT_QUOTES, 'UTF-8') ?></p>
        <form method="POST" action="/api/v1/cart/items">
            <input type="hidden" name="product_id" value="<?= (int)($product['id'] ?? 0) ?>">
            <input type="number" name="quantity" value="1" min="1" max="10">
            <button type="submit" class="btn">Add to Cart</button>
        </form>
    </main>
</body>
</html>
