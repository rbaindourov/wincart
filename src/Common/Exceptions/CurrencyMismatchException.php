<?php

declare(strict_types=1);

namespace WinCart\Common\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an operation is attempted between two incompatible currencies.
 */
class CurrencyMismatchException extends InvalidArgumentException
{
}
