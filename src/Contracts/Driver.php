<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Contracts;

use Mastertek\IranSms\DTO\SmsMessage;

interface Driver
{
    /**
     * Driver name.
     */
    public function name(): string;

    /**
     * Provider credit.
     */
    public function credit(): int;

    /**
     * Send message.
     */
    public function send(SmsMessage $message): SmsResponse;

    /**
     * Check feature support.
     */
    public function supports(string $feature): bool;
}