<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'winCart Setup Wizard', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50:  #f8fafc;
            --blue-600:  #2563eb;
            --blue-700:  #1d4ed8;
            --blue-50:   #eff6ff;
            --green-600: #16a34a;
            --green-100: #dcfce7;
            --green-800: #166534;
            --red-600:   #dc2626;
            --red-100:   #fee2e2;
            --red-800:   #991b1b;
            --amber-600: #d97706;
            --amber-100: #fef3c7;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--slate-50);
            color: var(--slate-800);
            line-height: 1.5;
            padding: 2rem 1rem;
        }
        .wizard-container {
            max-width: 680px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.07), 0 4px 6px -2px rgba(0,0,0,0.04);
            border: 1px solid var(--slate-200);
            overflow: hidden;
        }
        .wizard-header {
            background: var(--slate-900);
            color: #ffffff;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .wizard-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        .wizard-header .version-tag {
            font-size: 0.75rem;
            background: var(--slate-700);
            color: var(--slate-200);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }
        .stepper {
            display: flex;
            background: #ffffff;
            border-bottom: 1px solid var(--slate-200);
            padding: 0.75rem 1rem;
            overflow-x: auto;
        }
        .step-item {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--slate-500);
            text-decoration: none;
            padding: 0.25rem 0.5rem;
            white-space: nowrap;
        }
        .step-item.active {
            color: var(--blue-600);
            font-weight: 600;
        }
        .step-item.completed {
            color: var(--green-600);
        }
        .step-badge {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--slate-100);
            color: var(--slate-600);
        }
        .step-item.active .step-badge {
            background: var(--blue-600);
            color: #ffffff;
        }
        .step-item.completed .step-badge {
            background: var(--green-100);
            color: var(--green-800);
        }
        .wizard-body {
            padding: 2rem;
        }
        .wizard-body h2 {
            font-size: 1.25rem;
            color: var(--slate-900);
            margin-bottom: 0.5rem;
        }
        .wizard-body p.subtitle {
            color: var(--slate-600);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--slate-700);
            margin-bottom: 0.375rem;
        }
        .form-control {
            width: 100%;
            padding: 0.625rem 0.75rem;
            font-size: 0.875rem;
            border: 1px solid var(--slate-200);
            border-radius: 6px;
            background: #ffffff;
            color: var(--slate-800);
            transition: border-color 0.15s ease-in-out;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--blue-600);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-text {
            font-size: 0.75rem;
            color: var(--slate-500);
            margin-top: 0.25rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: background-color 0.15s ease-in-out;
        }
        .btn-primary {
            background: var(--blue-600);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--blue-700);
        }
        .btn-secondary {
            background: var(--slate-100);
            color: var(--slate-700);
        }
        .btn-secondary:hover {
            background: var(--slate-200);
        }
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 9999px;
        }
        .badge-success { background: var(--green-100); color: var(--green-800); }
        .badge-danger  { background: var(--red-100); color: var(--red-800); }
        .badge-warning { background: var(--amber-100); color: var(--amber-600); }
        .table-checks {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        .table-checks td {
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid var(--slate-100);
            font-size: 0.875rem;
        }
        .table-checks tr:last-child td {
            border-bottom: none;
        }
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .alert-danger {
            background: var(--red-100);
            color: var(--red-800);
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: var(--green-100);
            color: var(--green-800);
            border: 1px solid #bbf7d0;
        }
        .alert-info {
            background: var(--blue-50);
            color: var(--blue-700);
            border: 1px solid #bfdbfe;
        }
        .token-box {
            background: var(--slate-900);
            color: #38bdf8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            padding: 1rem;
            border-radius: 6px;
            word-break: break-all;
            margin: 1rem 0;
            user-select: all;
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <header class="wizard-header">
            <h1>winCart Quick Installer</h1>
            <span class="version-tag">v1.0.0</span>
        </header>

        <nav class="stepper" aria-label="Installer Progress">
            <?php
            $steps = [
                1 => 'Environment',
                2 => 'Database',
                3 => 'Profile',
                4 => 'Admin',
                5 => 'Complete',
            ];
            $currentStep = (int)($step ?? 1);
            foreach ($steps as $sNum => $sLabel) :
                $statusClass = '';
                if ($sNum === $currentStep) {
                    $statusClass = 'active';
                } elseif ($sNum < $currentStep) {
                    $statusClass = 'completed';
                }
                ?>
            <span class="step-item <?= $statusClass ?>">
                <span class="step-badge"><?= $sNum < $currentStep ? '&#10003;' : $sNum ?></span>
                <?= htmlspecialchars($sLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endforeach; ?>
        </nav>

        <main class="wizard-body">
            <?php if (!empty($flashSuccess)) : ?>
                <div class="alert alert-success"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (!empty($flashError)) : ?>
                <div class="alert alert-danger"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?= $content ?? '' ?>
        </main>
    </div>
</body>
</html>
