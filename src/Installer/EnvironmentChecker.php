<?php

declare(strict_types=1);

namespace WinCart\Installer;

final class EnvironmentChecker
{
    /**
     * @return array{
     *     all_passed: bool,
     *     php_version: array{required: string, current: string, passed: bool},
     *     extensions: array<string, array{required: bool, loaded: bool, passed: bool}>,
     *     permissions: array<string, array{path: string, writable: bool, passed: bool}>,
     *     errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public static function check(string $baseDir = ''): array
    {
        if ($baseDir === '') {
            $baseDir = dirname(__DIR__, 2);
        }

        $errors = [];
        $warnings = [];

        // 1. PHP Version >= 8.2.0
        $phpPassed = PHP_VERSION_ID >= 80200;
        if (!$phpPassed) {
            $errors[] = 'PHP 8.2.0 or higher is required. Current version: ' . PHP_VERSION;
        }

        // 2. Mandatory Extensions
        $requiredExtensions = [
            'pdo' => true,
            'json' => true,
            'mbstring' => true,
            'curl' => true,
            'openssl' => true,
        ];

        $extensionResults = [];
        foreach ($requiredExtensions as $ext => $isRequired) {
            $loaded = extension_loaded($ext);
            $extensionResults[$ext] = [
                'required' => $isRequired,
                'loaded' => $loaded,
                'passed' => $loaded,
            ];
            if (!$loaded) {
                $errors[] = "Missing required PHP extension: {$ext}";
            }
        }

        // PDO Drivers: at least one of pdo_sqlite or pdo_mysql must be available
        $hasSqlite = extension_loaded('pdo_sqlite');
        $hasMysql = extension_loaded('pdo_mysql');

        $extensionResults['pdo_sqlite'] = [
            'required' => false,
            'loaded' => $hasSqlite,
            'passed' => $hasSqlite,
        ];
        $extensionResults['pdo_mysql'] = [
            'required' => false,
            'loaded' => $hasMysql,
            'passed' => $hasMysql,
        ];

        if (!$hasSqlite && !$hasMysql) {
            $errors[] = 'At least one PDO database driver (pdo_sqlite or pdo_mysql) is required.';
        }

        // 3. Storage Directory Permissions
        $storageDir = $baseDir . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }
        $storageWritable = is_dir($storageDir) && is_writable($storageDir);
        if (!$storageWritable) {
            $errors[] = 'Directory ' . $storageDir . ' must be writable.';
        }

        $permissionsResults = [
            'storage' => [
                'path' => 'storage/',
                'writable' => $storageWritable,
                'passed' => $storageWritable,
            ],
        ];

        $allPassed = empty($errors);

        return [
            'all_passed' => $allPassed,
            'php_version' => [
                'required' => '>= 8.2.0',
                'current' => PHP_VERSION,
                'passed' => $phpPassed,
            ],
            'extensions' => $extensionResults,
            'permissions' => $permissionsResults,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
