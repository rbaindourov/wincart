<?php

declare(strict_types=1);

namespace WinCart\Installer\Cli;

final class InteractivePrompter
{
    public static function isInteractive(): bool
    {
        return defined('STDIN')
            && is_resource(STDIN)
            && stream_isatty(STDIN)
            && !feof(STDIN);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function prompt(array $options): array
    {
        if (!self::isInteractive()) {
            return $options;
        }

        echo "========================================================\n";
        echo "              winCart Setup Wizard\n";
        echo "========================================================\n";

        $options['db_driver'] = self::ask(
            'Database driver (sqlite/mysql)',
            (string)($options['db_driver'] ?? 'sqlite')
        );
        if ($options['db_driver'] === 'sqlite') {
            $defaultPath = $options['db_path'] ?? dirname(__DIR__, 4) . '/storage/database.sqlite';
            $options['db_path'] = self::ask('SQLite database path', (string)$defaultPath);
        } else {
            $options['db_host'] = self::ask('MySQL Host', (string)($options['db_host'] ?? '127.0.0.1'));
            $options['db_port'] = (int)self::ask('MySQL Port', (string)($options['db_port'] ?? '3306'));
            $options['db_name'] = self::ask('MySQL Database Name', (string)($options['db_name'] ?? 'wincart'));
            $options['db_user'] = self::ask('MySQL Username', (string)($options['db_user'] ?? 'root'));
            $options['db_pass'] = self::ask('MySQL Password', (string)($options['db_pass'] ?? ''));
        }

        $options['admin_user'] = self::ask('Admin Username', (string)($options['admin_user'] ?? 'admin'));
        $options['admin_email'] = self::ask('Admin Email', (string)($options['admin_email'] ?? 'admin@wincart.local'));
        $options['admin_pass'] = self::ask('Admin Password', (string)($options['admin_pass'] ?? 'Secret123!'));
        $options['store_name'] = self::ask('Store Name', (string)($options['store_name'] ?? 'winCart Store'));
        $options['store_currency'] = strtoupper(self::ask(
            'Store Currency (ISO 4217)',
            (string)($options['store_currency'] ?? 'USD')
        ));

        $seedDefault = !empty($options['demo_data']) ? 'y' : 'n';
        $seedAns = self::ask('Seed demo catalog data? (y/N)', $seedDefault);
        $options['demo_data'] = in_array(strtolower($seedAns), ['y', 'yes', '1', 'true'], true);

        return $options;
    }

    private static function ask(string $prompt, string $default): string
    {
        if (!self::isInteractive()) {
            return $default;
        }

        echo "{$prompt} [{$default}]: ";
        $line = fgets(STDIN);
        if ($line === false) {
            return $default;
        }
        $trimmed = trim($line);
        return $trimmed !== '' ? $trimmed : $default;
    }
}
