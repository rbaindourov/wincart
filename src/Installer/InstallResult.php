<?php

declare(strict_types=1);

namespace WinCart\Installer;

final class InstallResult
{
    /**
     * @param list<string> $appliedMigrations
     * @param list<string> $errors
     */
    public function __construct(
        private bool $success,
        private int $exitCode,
        private string $message,
        private ?string $patToken = null,
        private array $appliedMigrations = [],
        private array $errors = []
    ) {
    }

    /**
     * @param list<string> $appliedMigrations
     */
    public static function success(
        string $message,
        ?string $patToken = null,
        array $appliedMigrations = []
    ): self {
        return new self(
            success: true,
            exitCode: 0,
            message: $message,
            patToken: $patToken,
            appliedMigrations: $appliedMigrations,
            errors: []
        );
    }

    /**
     * @param list<string> $errors
     */
    public static function failure(array $errors, int $exitCode = 1): self
    {
        return new self(
            success: false,
            exitCode: $exitCode,
            message: implode('; ', $errors),
            patToken: null,
            appliedMigrations: [],
            errors: $errors
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPatToken(): ?string
    {
        return $this->patToken;
    }

    /**
     * @return list<string>
     */
    public function getAppliedMigrations(): array
    {
        return $this->appliedMigrations;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
