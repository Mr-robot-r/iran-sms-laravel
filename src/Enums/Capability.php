<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Enums;

enum Capability: string
{
    case Otp = 'otp';

    case Pattern = 'pattern';

    case Text = 'text';

    case Phonebook = 'phonebook';

    case Bulk = 'bulk';

    case Unicode = 'unicode';

    case Flash = 'flash';
}