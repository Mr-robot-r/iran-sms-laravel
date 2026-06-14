<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Exceptions;

use LogicException;

/**
 * Throw exception if driver does not support the method to send SMS.
 */
final class UnsupportedMethodException extends LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function make(
        string $driver,
        string $method,
        ?string $alternative = null
    ): self {
        $message = sprintf(
            'Provider "%s" does not support "%s" SMS method%s.',
            $driver,
            $method,
            $alternative ? ", use \"{$alternative}\" instead" : ''
        );

        return new self($message);
    }
}
