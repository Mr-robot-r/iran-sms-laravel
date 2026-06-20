<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Contracts;

interface Phonebook
{
    public function createGroup(
        string $name,
        ?string $description = null
    ): array;

    public function editGroup(
        string $groupId,
        string $name,
        ?string $description = null
    ): array;

    public function deleteGroup(
        string $groupId
    ): array;

    public function getGroups(): array;

    /**
     * @param array<string,mixed> $contact
     */
    public function addContact(
        array $contact
    ): array;

    public function getContacts(
        ?string $groupId = null,
        int $page = 1,
        int $perPage = 50
    ): array;

    public function deleteContact(
        string $contactId
    ): array;

    public function getContactsCount(
        string $groupId
    ): array;

    public function sendToGroup(
        string $groupId,
        string $message,
        ?string $from = null
    ): array;
}