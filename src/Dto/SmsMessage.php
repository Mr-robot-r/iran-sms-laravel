<?php

declare(strict_types=1);

namespace Mastertek\IranSms\DTO;

use Mastertek\IranSms\Enums\Type;

final readonly class SmsMessage
{
    /**
     * @param list<string> $phones
     * @param array<string,mixed>|string $content
     */
    public function __construct(
        public Type $type,
        public array $phones,
        public string|array $content,
        public ?string $from = null,
    ) {}
}