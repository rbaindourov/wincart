<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Value object representing the result of a CLI subprocess execution.
 */
class CliResult
{
    public function __construct(
        private int $exitCode,
        private string $stdout,
        private string $stderr
    ) {
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function getOutput(): string
    {
        return $this->stdout . ($this->stderr !== '' ? "\n" . $this->stderr : '');
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
