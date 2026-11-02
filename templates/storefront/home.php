<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>winCart Storefront</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fafafa; color: #171717; margin: 0; }
        header { background: #171717; color: #fff; padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #fff; text-decoration: none; font-weight: 600; margin-left: 1.5rem; }
        .hero { text-align: center; padding: 4rem 1rem; background: #f5f5f5; border-bottom: 1px solid #e5e5e5; }
        .hero h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        .container { max-width: 1200px; margin: 3rem auto; padding: 0 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 2rem; }
        .product-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; }
        .price { font-size: 1.25rem; font-weight: 700; color: #059669; margin: 0.5rem 0; }
        .btn { display: inline-block; background: #2563eb; color: #fff; padding: 0.5rem 1rem; border-radius: 4px; text-decoration: none; text-align: center; }
    </style>
</head>
<body>
    <header>
        <h2>winCart Store</h2>
        <nav>
            <a href="/">Home</a>
            <a href="/category/turntables">Turntables</a>
            <a href="/category/laptops">Laptops</a>
            <a href="/category/amplifiers">Amplifiers</a>
            <a href="/admin">Admin</a>
        </nav>
    </header>
    <section class="hero">
        <h1>Welcome to winCart</h1>
        <p>Ultra-fast modern PHP e-commerce platform.</p>
    </section>
    <main class="container">
        <h2>Featured Catalog</h2>
        <div class="grid">
            <?php foreach ($products as $p) : ?>
                <div class="product-card">
                    <h3><a href="/products/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></a></h3>
                    <p class="price">$<?= number_format(((int)$p['price']) / 100, 2) ?></p>
                    <a class="btn" href="/products/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>">View Product</a>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
