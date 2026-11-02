<?php

declare(strict_types=1);

namespace WinCart\Installer\Cli;

use WinCart\Installer\InstallerService;

final class InstallCommand
{
    /**
     * @param array<string, mixed> $options
     */
    public function execute(array $options): int
    {
        if (!empty($options['help'])) {
            echo self::getHelp();
            return 0;
        }

        // Prompt only if interactive and unattended flag not set
        if (empty($options['unattended']) && InteractivePrompter::isInteractive()) {
            $options = InteractivePrompter::prompt($options);
        }

        echo "Starting winCart installation...\n";
        echo "Database driver: " . ($options['db_driver'] ?? 'sqlite') . "\n";

        $service = new InstallerService();
        $result = $service->install($options);

        if (!$result->isSuccess()) {
            fwrite(STDERR, "\nInstallation failed:\n");
            foreach ($result->getErrors() as $err) {
                fwrite(STDERR, "  - {$err}\n");
            }
            return $result->getExitCode();
        }

        echo "\n========================================================\n";
        echo "Installation completed successfully.\n";
        echo "========================================================\n";
        echo "Admin Email: " . ($options['admin_email'] ?? 'admin@wincart.local') . "\n";
        echo "Store Name:  " . ($options['store_name'] ?? 'winCart Store') . "\n";
        if ($result->getPatToken() !== null) {
            echo "Admin Personal Access Token (PAT):\n";
            echo "  " . $result->getPatToken() . "\n";
            echo "(Save this token now; it cannot be shown again.)\n";
        }
        echo "Applied Migrations: " . count($result->getAppliedMigrations()) . " files.\n";
        echo "========================================================\n";

        return 0;
    }

    public static function getHelp(): string
    {
        return <<<HELP
winCart CLI Installer

Usage:
  bin/wincart install [options]

Options:
  -n, --unattended, --non-interactive  Run non-interactively with defaults
  --db-driver=<driver>                 Database driver: sqlite (default) | mysql
  --db-path=<path>                     SQLite database path (default: storage/database.sqlite)
  --db-host=<host>                     MySQL host (default: 127.0.0.1)
  --db-port=<port>                     MySQL port (default: 3306)
  --db-name=<name>                     MySQL database name (default: wincart)
  --db-user=<user>                     MySQL username (default: root)
  --db-pass=<pass>                     MySQL password
  --admin-user=<user>                  Admin username (default: admin)
  --admin-email=<email>                Admin email (default: admin@wincart.local)
  --admin-pass=<pass>                  Admin password (default: Secret123!)
  --store-name=<name>                  Store / site name (default: winCart Store)
  --store-currency=<iso>               Currency code (default: USD)
  --demo-data, --seed                  Seed demo catalog and fixtures
  --lockfile-path=<path>               Custom installed.lock destination
  -f, --force                          Force install even if locked
  -h, --help                           Show this help message

HELP;
    }

    public static function getGlobalHelp(): string
    {
        return <<<HELP
winCart CLI Management & Installer (v1.0.0)

Usage:
  bin/wincart <command> [options]

Available commands:
  install    Run automated store installation and database migrations

Run 'bin/wincart install --help' for details on installer options.

HELP;
    }
}
