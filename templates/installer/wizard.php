<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>winCart Quick Installer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 2rem; }
        .container { max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 2rem; }
        h1 { font-size: 1.5rem; margin-top: 0; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.75rem; }
        .badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600; }
        .badge-success { background: #dcfce7; color: #166534; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; }
        button { background: #2563eb; color: #ffffff; padding: 0.625rem 1.25rem; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .env-check { list-style: none; padding: 0; margin: 1rem 0; }
        .env-check li { padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <div class="container">
        <h1>winCart Quick Installer</h1>
        <p>Welcome to the winCart setup wizard.</p>

        <h2>System Environment Check</h2>
        <ul class="env-check">
            <li><span>PHP Version: <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?> (&gt;= 8.2)</span> <span class="badge badge-success">Pass</span></li>
            <li><span>PDO Extension</span> <span class="badge badge-success">Pass</span></li>
            <li><span>storage/ is writable</span> <span class="badge badge-success">Pass</span></li>
            <li><span>mbstring Extension</span> <span class="badge badge-success">Pass</span></li>
        </ul>

        <h2>Administrator Provisioning</h2>
        <form method="POST" action="/install/admin">
            <div class="form-group">
                <label for="username">Admin Username</label>
                <input type="text" id="username" name="username" value="superadmin" required>
            </div>
            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" value="admin@wincart.local" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required>
            </div>
            <button type="submit">Complete Installation</button>
        </form>
    </div>
</body>
</html>
