<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Isolated process execution wrapper for bin/wincart CLI management commands.
 */
class CliRunner
{
    private string $binaryPath;

    public function __construct(
        private TestEnvironment $env,
        ?string $binaryPath = null
    ) {
        $baseDir = dirname(__DIR__, 3);
        $this->binaryPath = $binaryPath ?? ($baseDir . '/bin/wincart');
    }

    /**
     * @param array<int|string, string|int> $args
     * @param array<string, string|int|bool> $options
     * @param array<string, string> $extraEnv
     */
    public function run(
        string $command,
        array $args = [],
        array $options = [],
        array $extraEnv = []
    ): CliResult {
        $cmdParts = [PHP_BINARY, $this->binaryPath, escapeshellarg($command)];

        foreach ($args as $key => $val) {
            if (is_string($key)) {
                $cmdParts[] = escapeshellarg($key);
            }
            $cmdParts[] = escapeshellarg((string)$val);
        }

        foreach ($options as $opt => $val) {
            $prefix = strlen($opt) === 1 ? '-' : '--';
            if ($val === true) {
                $cmdParts[] = $prefix . $opt;
            } elseif ($val !== false && $val !== null) {
                $cmdParts[] = $prefix . $opt . '=' . escapeshellarg((string)$val);
            }
        }

        $commandLine = implode(' ', $cmdParts);
        return $this->runRaw($commandLine, $extraEnv);
    }

    /**
     * @param array<string, string> $extraEnv
     */
    public function runRaw(string $commandLine, array $extraEnv = []): CliResult
    {
        $env = array_merge(
            getenv(),
            $this->env->getEnvVariables(),
            $extraEnv
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($commandLine, $descriptors, $pipes, dirname($this->binaryPath, 2), $env);

        if (!is_resource($process)) {
            return new CliResult(1, '', 'Failed to spawn subprocess: ' . $commandLine);
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new CliResult($exitCode, $stdout, $stderr);
    }
}
