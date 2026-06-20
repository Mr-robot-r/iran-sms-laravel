<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Contracts;

interface Sms
{
    /**
     * Create OTP message.
     */
    public function otp(string $phone, string $message): static;

    /**
     * Create text message.
     *
     * @param string|list<string> $phones
     */
    public function text(string|array $phones, string $message): static;

    /**
     * Create pattern message.
     *
     * @param string|list<string> $phones
     * @param array<string,mixed> $variables
     */
    public function pattern(
        string|array $phones,
        string $pattern,
        array $variables
    ): static;

    /**
     * Set sender number.
     */
    public function from(string $from): static;

    /**
     * Send message.
     */
    public function send(): SmsResponse;
}