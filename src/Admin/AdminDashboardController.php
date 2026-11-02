<?php

declare(strict_types=1);

namespace WinCart\Admin;

use PDO;

final class AdminDashboardController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function index(): string
    {
        $aggregator = new AdminMetricAggregator($this->pdo);
        $metrics = $aggregator->getMetrics();

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/dashboard.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>winCart Admin Dashboard</h1>';
        }
        return (string)ob_get_clean();
    }

    /**
     * @return array<string, mixed>
     */
    public function apiMetrics(): array
    {
        $aggregator = new AdminMetricAggregator($this->pdo);
        return $aggregator->getMetrics();
    }
}
