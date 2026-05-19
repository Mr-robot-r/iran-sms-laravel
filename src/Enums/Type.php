<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Enums;

/**
 * @internal
 *
 * Supported SMS types.
 */
enum Type: string
{
    case Otp = 'otp';
    case Pattern = 'pattern';
    case Text = 'text';
}
