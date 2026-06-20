<?php

declare(strict_types=1);

namespace Mastertek\IranSms\DTO;

final readonly class Contact
{
    public function __construct(
        public string $mobile,
        public string $groupId,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $corporation = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?int $gender = null,
    ) {}
}