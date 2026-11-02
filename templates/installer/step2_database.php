<h2>Step 2: Database Configuration &amp; Migrations</h2>
<p class="subtitle">Select your database driver and initialize catalog, customer, and order tables.</p>

<form method="POST" action="/install/database">
    <div class="form-group">
        <label for="db_driver">Database Engine</label>
        <select id="db_driver" name="db_driver" class="form-control" onchange="toggleDbFields(this.value)">
            <option value="sqlite" <?= ($state['db_driver'] ?? 'sqlite') === 'sqlite' ? 'selected' : '' ?>>SQLite (Embedded / Zero Configuration)</option>
            <option value="mysql" <?= ($state['db_driver'] ?? '') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
        </select>
    </div>

    <div id="sqlite_fields" style="<?= ($state['db_driver'] ?? 'sqlite') === 'sqlite' ? '' : 'display:none;' ?>">
        <div class="form-group">
            <label for="db_path">SQLite Database Path</label>
            <input type="text" id="db_path" name="db_path" class="form-control" value="<?= htmlspecialchars($state['db_path'] ?? 'storage/database.sqlite', ENT_QUOTES, 'UTF-8') ?>">
            <p class="form-text">Relative or absolute path. The database file will be created automatically if missing.</p>
        </div>
    </div>

    <div id="mysql_fields" style="<?= ($state['db_driver'] ?? '') === 'mysql' ? '' : 'display:none;' ?>">
        <div class="form-group">
            <label for="db_host">MySQL Host</label>
            <input type="text" id="db_host" name="db_host" class="form-control" value="<?= htmlspecialchars($state['db_host'] ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label for="db_port">MySQL Port</label>
            <input type="number" id="db_port" name="db_port" class="form-control" value="<?= (int)($state['db_port'] ?? 3306) ?>">
        </div>
        <div class="form-group">
            <label for="db_name">Database Name</label>
            <input type="text" id="db_name" name="db_name" class="form-control" value="<?= htmlspecialchars($state['db_name'] ?? 'wincart', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label for="db_user">Username</label>
            <input type="text" id="db_user" name="db_user" class="form-control" value="<?= htmlspecialchars($state['db_user'] ?? 'root', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label for="db_pass">Password</label>
            <input type="password" id="db_pass" name="db_pass" class="form-control" value="<?= htmlspecialchars($state['db_pass'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <div class="actions-bar">
        <a href="/install?step=1" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Connect &amp; Run Migrations &rarr;</button>
    </div>
</form>

<script>
function toggleDbFields(driver) {
    document.getElementById('sqlite_fields').style.display = driver === 'sqlite' ? 'block' : 'none';
    document.getElementById('mysql_fields').style.display = driver === 'mysql' ? 'block' : 'none';
}
</script>
