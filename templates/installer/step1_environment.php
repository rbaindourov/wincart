<h2>Step 1: Environment & Diagnostics</h2>
<p class="subtitle">Checking server capabilities, required PHP extensions, and filesystem writability.</p>

<table class="table-checks">
    <tbody>
        <tr>
            <td>
                <strong>PHP Version:</strong>
                <?= htmlspecialchars($env['php_version']['current'] ?? PHP_VERSION, ENT_QUOTES, 'UTF-8') ?>
                <span class="form-text">(Requires &gt;= 8.2.0)</span>
            </td>
            <td style="text-align: right;">
                <?php if (!empty($env['php_version']['passed'])) : ?>
                    <span class="badge badge-success">Pass</span>
                <?php else : ?>
                    <span class="badge badge-danger">Fail</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>
                <strong>PDO Extension</strong>
                <span class="form-text">Core database abstraction layer</span>
            </td>
            <td style="text-align: right;">
                <?php if (!empty($env['extensions']['pdo']['passed'])) : ?>
                    <span class="badge badge-success">Pass</span>
                <?php else : ?>
                    <span class="badge badge-danger">Fail</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>
                <strong>storage/ is writable</strong>
                <span class="form-text">Directory for database, logs, and lockfiles</span>
            </td>
            <td style="text-align: right;">
                <?php if (!empty($env['permissions']['storage']['passed'])) : ?>
                    <span class="badge badge-success">Pass</span>
                <?php else : ?>
                    <span class="badge badge-danger">Fail</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>
                <strong>mbstring Extension</strong>
                <span class="form-text">Multibyte UTF-8 string processing</span>
            </td>
            <td style="text-align: right;">
                <?php if (!empty($env['extensions']['mbstring']['passed'])) : ?>
                    <span class="badge badge-success">Pass</span>
                <?php else : ?>
                    <span class="badge badge-danger">Fail</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>
                <strong>json &amp; openssl Extensions</strong>
                <span class="form-text">Cryptographic tokens &amp; API payloads</span>
            </td>
            <td style="text-align: right;">
                <?php
                $hasCrypto = !empty($env['extensions']['json']['passed'])
                    && !empty($env['extensions']['openssl']['passed']);
                if ($hasCrypto) : ?>
                    <span class="badge badge-success">Pass</span>
                <?php else : ?>
                    <span class="badge badge-danger">Fail</span>
                <?php endif; ?>
            </td>
        </tr>
    </tbody>
</table>

<div class="actions-bar">
    <span></span>
    <?php if (!empty($env['all_passed'])) : ?>
        <a href="/install?step=2" class="btn btn-primary">Continue to Database &rarr;</a>
    <?php else : ?>
        <button class="btn btn-secondary" disabled>Please resolve requirements above</button>
    <?php endif; ?>
</div>
