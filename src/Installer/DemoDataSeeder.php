<?php

declare(strict_types=1);

namespace WinCart\Installer;

use PDO;
use WinCart\Database\DemoDataSeeder as BaseDemoDataSeeder;

final class DemoDataSeeder
{
    /**
     * @param PDO $pdo
     * @param array<string, mixed> $options
     * @return array<string, int>
     */
    public static function seed(PDO $pdo, array $options = []): array
    {
        return BaseDemoDataSeeder::seed($pdo, $options);
    }
}
