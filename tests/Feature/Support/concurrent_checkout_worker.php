<?php

/**
 * Concurrent Checkout Worker Process for winCart Milestone 3 Adversarial Challenge.
 *
 * Simulates an isolated web request / agent process attempting to execute checkout.
 * Synchronizes via file lock barrier to maximize race condition contention.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use WinCart\Http\Kernel;

$options = getopt('', [
    'db:',
    'lock:',
    'barrier:',
    'payload:',
    'token::',
    'out:',
]);

$dbPath = $options['db'] ?? '';
$lockPath = $options['lock'] ?? '';
$barrierPath = $options['barrier'] ?? '';
$payloadRaw = $options['payload'] ?? '{}';
$token = $options['token'] ?? null;
$outPath = $options['out'] ?? '';

if ($dbPath === '' || $barrierPath === '' || $outPath === '') {
    file_put_contents('php://stderr', "Missing required arguments\n");
    exit(1);
}

// 1. Initialize Kernel and pre-warm PDO connection
$kernel = new Kernel([
    'DB_DATABASE' => $dbPath,
    'INSTALL_LOCK_PATH' => $lockPath,
]);

// Ensure connection is established and SQLite pragmas are active
$pdo = $kernel->getPdo();

// 2. Wait on Barrier: Block on shared lock until master releases exclusive lock
$barrierFp = fopen($barrierPath, 'r');
if (!$barrierFp) {
    file_put_contents('php://stderr', "Failed to open barrier file: {$barrierPath}\n");
    exit(1);
}

// Blocks until master releases the barrier
flock($barrierFp, LOCK_SH);
fclose($barrierFp);

// 3. Immediately fire concurrent checkout request
$start = microtime(true);
$headers = ['Content-Type' => 'application/json'];
if ($token !== null && $token !== '') {
    $headers['Authorization'] = 'Bearer ' . $token;
}

try {
    $response = $kernel->handleRequest('POST', '/api/v1/checkout', $payloadRaw, $headers);
    $duration = microtime(true) - $start;

    $result = [
        'pid' => getmypid(),
        'status_code' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body' => $response->getBody(),
        'json' => $response->getJson(),
        'duration' => $duration,
        'error' => null,
    ];
} catch (Throwable $e) {
    $duration = microtime(true) - $start;
    $result = [
        'pid' => getmypid(),
        'status_code' => 500,
        'headers' => [],
        'body' => $e->getMessage(),
        'json' => null,
        'duration' => $duration,
        'error' => $e->getMessage(),
    ];
}

file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit(0);
