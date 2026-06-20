<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Contracts;

interface SmsResponse
{
    /**
     * Was request successful?
     */
    public function successful(): bool;

    /**
     * Was request failed?
     */
    public function failed(): bool;

    /**
     * Provider response message.
     */
    public function message(): ?string;

    /**
     * Provider response code.
     */
    public function code(): string|int|null;

    /**
     * Provider name.
     */
    public function provider(): string;

    /**
     * Provider message id.
     */
    public function messageId(): string|int|null;

    /**
     * Raw provider response.
     *
     * @return array<string,mixed>
     */
    public function data(): array;
}