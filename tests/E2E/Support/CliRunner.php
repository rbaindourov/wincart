<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Isolated process execution wrapper for bin/wincart CLI management commands.
 * Uses non-blocking stream_select to prevent pipe buffer deadlocks on large STDERR output.
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

    public function isAvailable(): bool
    {
        return file_exists($this->binaryPath);
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
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
        array $extraEnv = [],
        float $timeout = 10.0
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
        return $this->runRaw($commandLine, $extraEnv, $timeout);
    }

    /**
     * @param array<string, string> $extraEnv
     */
    public function runRaw(string $commandLine, array $extraEnv = [], float $timeout = 10.0): CliResult
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

        // Close STDIN immediately for non-interactive execution
        fclose($pipes[0]);

        // Put STDOUT and STDERR into non-blocking mode to prevent pipe buffer deadlock
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $readPipes = [1 => $pipes[1], 2 => $pipes[2]];
        $startTime = microtime(true);
        $exitCode = -1;

        while (!empty($readPipes)) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                proc_terminate($process, 9);
                foreach ($readPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
                return new CliResult(
                    124,
                    $stdout,
                    $stderr . "\n[TIMEOUT] Command timed out after {$timeout}s: {$commandLine}"
                );
            }

            $r = $readPipes;
            $w = null;
            $e = null;

            $remaining = max(0.001, $timeout - $elapsed);
            $sec = (int)floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $numChanged = stream_select($r, $w, $e, $sec, $usec);
            if ($numChanged === false) {
                break;
            }

            if ($numChanged > 0) {
                if (isset($readPipes[1]) && in_array($readPipes[1], $r, true)) {
                    $chunk = fread($readPipes[1], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $stdout .= $chunk;
                    } elseif (feof($readPipes[1])) {
                        fclose($readPipes[1]);
                        unset($readPipes[1]);
                    }
                }

                if (isset($readPipes[2]) && in_array($readPipes[2], $r, true)) {
                    $chunk = fread($readPipes[2], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $stderr .= $chunk;
                    } elseif (feof($readPipes[2])) {
                        fclose($readPipes[2]);
                        unset($readPipes[2]);
                    }
                }
            } else {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    foreach ([1, 2] as $fd) {
                        if (isset($readPipes[$fd])) {
                            while (($chunk = fread($readPipes[$fd], 8192)) !== false && $chunk !== '') {
                                if ($fd === 1) {
                                    $stdout .= $chunk;
                                } else {
                                    $stderr .= $chunk;
                                }
                            }
                            fclose($readPipes[$fd]);
                            unset($readPipes[$fd]);
                        }
                    }
                    break;
                }
            }
        }

        foreach ($readPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $closeCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $closeCode;
        }

        return new CliResult($exitCode, $stdout, $stderr);
    }
}
