<?php

declare(strict_types=1);

namespace Mastertek\IranSms\DTO;

final readonly class Group
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }
}