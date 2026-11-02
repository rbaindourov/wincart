<h2>Step 3: Store Profile &amp; Commerce Settings</h2>
<p class="subtitle">Set your store branding, primary currency, and tax/shipping defaults.</p>

<form method="POST" action="/install/profile">
    <div class="form-group">
        <label for="store_name">Store Brand / Title</label>
        <input type="text" id="store_name" name="store_name" class="form-control" value="<?= htmlspecialchars($state['store_name'] ?? 'winCart Store', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="form-group">
        <label for="store_currency">Primary Currency</label>
        <select id="store_currency" name="store_currency" class="form-control">
            <option value="USD" <?= ($state['store_currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD - US Dollar ($)</option>
            <option value="EUR" <?= ($state['store_currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR - Euro (€)</option>
            <option value="GBP" <?= ($state['store_currency'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP - British Pound (£)</option>
            <option value="CAD" <?= ($state['store_currency'] ?? '') === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar ($)</option>
            <option value="AUD" <?= ($state['store_currency'] ?? '') === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar ($)</option>
        </select>
    </div>

    <div class="actions-bar">
        <a href="/install?step=2" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Save &amp; Continue &rarr;</button>
    </div>
</form>
