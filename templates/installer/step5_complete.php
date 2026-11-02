<h2>Step 5: Review &amp; Finalize Installation</h2>
<p class="subtitle">Your winCart installation is configured and ready to be locked.</p>

<div class="alert alert-info">
    <strong>Admin Personal Access Token (PAT):</strong>
    <p class="form-text">Save this token now. It will not be revealed again and is required for REST API &amp; MCP management.</p>
</div>

<div class="token-box"><?= htmlspecialchars($state['pat_token'] ?? 'wincart_pat_initialized', ENT_QUOTES, 'UTF-8') ?></div>

<form method="POST" action="/install/finalize">
    <div class="form-group" style="margin-top: 1.5rem;">
        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
            <input type="checkbox" name="demo_data" value="1" <?= !empty($state['demo_data']) ? 'checked' : '' ?>>
            <span><strong>Populate sample catalog</strong> (HiFi audio turntables, amplifiers, demo customers, and coupons)</span>
        </label>
    </div>

    <div class="actions-bar">
        <a href="/install?step=4" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Finalize &amp; Seal Store &rarr;</button>
    </div>
</form>
