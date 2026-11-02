<?php

declare(strict_types=1);

use WinCart\Installer\EnvironmentChecker;

$baseDir = dirname(__DIR__, 2);
$env = EnvironmentChecker::check($baseDir);
$step = 1;
$title = 'winCart Quick Installer';

ob_start();
require __DIR__ . '/step1_environment.php';
$content = (string)ob_get_clean();

require __DIR__ . '/layout.php';
