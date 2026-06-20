<?php

declare(strict_types=1);

namespace Mastertek\IranSms\DTO;

use Mastertek\IranSms\Contracts\SmsResponse as SmsResponseContract;

final readonly class SmsResponse implements SmsResponseContract
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private bool $successful,
        private ?string $message = null,
        private string|int|null $code = null,
        private string|int|null $messageId = null,
        private string $provider = '',
        private array $data = [],
    ) {
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    public function failed(): bool
    {
        return !$this->successful;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function code(): string|int|null
    {
        return $this->code;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function messageId(): string|int|null
    {
        return $this->messageId;
    }

    public function data(): array
    {
        return $this->data;
    }
}