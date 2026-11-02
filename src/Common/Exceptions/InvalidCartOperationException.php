<?php

declare(strict_types=1);

namespace WinCart\Common\Exceptions;

/**
 * Thrown when an invalid cart operation is requested (e.g., mismatched variation and product).
 */
class InvalidCartOperationException extends WinCartException
{
}
