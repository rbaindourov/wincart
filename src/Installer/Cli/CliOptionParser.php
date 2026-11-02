<?php

declare(strict_types=1);

namespace WinCart\Installer\Cli;

final class CliOptionParser
{
    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public static function parse(array $args): array
    {
        $options = [
            'unattended' => false,
            'db_driver' => 'sqlite',
            'db_path' => null,
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_name' => 'wincart',
            'db_user' => 'root',
            'db_pass' => '',
            'admin_user' => 'admin',
            'admin_email' => 'admin@wincart.local',
            'admin_pass' => 'Secret123!',
            'store_name' => 'winCart Store',
            'store_currency' => 'USD',
            'demo_data' => false,
            'lockfile_path' => null,
            'force' => false,
            'help' => false,
        ];

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '-n' || $arg === '--unattended' || $arg === '--non-interactive') {
                $options['unattended'] = true;
                continue;
            }
            if ($arg === '-f' || $arg === '--force') {
                $options['force'] = true;
                continue;
            }
            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--demo-data' || $arg === '--seed') {
                $options['demo_data'] = true;
                continue;
            }

            // Handle --key=val or --key val
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                $key = $body;
                $val = null;

                if (str_contains($body, '=')) {
                    [$key, $val] = explode('=', $body, 2);
                    $val = trim($val, "'\"");
                } elseif ($i + 1 < $count && !str_starts_with($args[$i + 1], '-')) {
                    $val = $args[++$i];
                } else {
                    $val = 'true';
                }

                self::assignOption($options, $key, $val);
            } elseif (str_starts_with($arg, '-')) {
                // Short options
                $flag = substr($arg, 1);
                if ($flag === 'n') {
                    $options['unattended'] = true;
                } elseif ($flag === 'f') {
                    $options['force'] = true;
                } elseif ($flag === 'h') {
                    $options['help'] = true;
                }
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function assignOption(array &$options, string $key, string $val): void
    {
        switch ($key) {
            case 'db-driver':
            case 'driver':
                $options['db_driver'] = strtolower($val);
                break;
            case 'db-path':
            case 'database':
            case 'db-file':
                $options['db_path'] = $val;
                break;
            case 'db-host':
            case 'host':
                $options['db_host'] = $val;
                break;
            case 'db-port':
            case 'port':
                $options['db_port'] = (int)$val;
                break;
            case 'db-name':
            case 'dbname':
                $options['db_name'] = $val;
                break;
            case 'db-user':
            case 'db-username':
            case 'user':
                $options['db_user'] = $val;
                break;
            case 'db-pass':
            case 'db-password':
            case 'pass':
                $options['db_pass'] = $val;
                break;
            case 'admin-user':
            case 'admin-username':
                $options['admin_user'] = $val;
                break;
            case 'admin-email':
            case 'email':
                $options['admin_email'] = $val;
                break;
            case 'admin-pass':
            case 'admin-password':
                $options['admin_pass'] = $val;
                break;
            case 'store-name':
            case 'site-name':
                $options['store_name'] = $val;
                break;
            case 'store-currency':
            case 'currency':
                $options['store_currency'] = strtoupper($val);
                break;
            case 'seed':
            case 'demo-data':
                $options['demo_data'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                break;
            case 'lockfile-path':
            case 'lock-path':
                $options['lockfile_path'] = $val;
                break;
            case 'non-interactive':
            case 'unattended':
                $options['unattended'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                break;
            case 'force':
                $options['force'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                break;
        }
    }
}
