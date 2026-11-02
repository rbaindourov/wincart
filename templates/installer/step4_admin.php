<h2>Step 4: Administrator Account Provisioning</h2>
<p class="subtitle">Create the root administrator credentials and generate your personal access token.</p>

<form method="POST" action="/install/admin">
    <div class="form-group">
        <label for="username">Admin Username</label>
        <input type="text" id="username" name="username" class="form-control" value="<?= htmlspecialchars($state['admin_user'] ?? 'superadmin', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="form-group">
        <label for="email">Admin Email Address</label>
        <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($state['admin_email'] ?? 'admin@wincart.local', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 8 characters" required>
    </div>

    <div class="form-group">
        <label for="password_confirmation">Confirm Password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" placeholder="Re-enter password" required>
    </div>

    <div class="actions-bar">
        <a href="/install?step=3" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Create Admin &rarr;</button>
    </div>
</form>
