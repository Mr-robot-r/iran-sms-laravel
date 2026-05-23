<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Contracts;

/**
 * Public API for interacting with the amyavari/iran-sms-laravel package
 */
interface Sms
{
    /**
     * Get SMS provider credit balance in Rials
     */
    public function credit(): int;

    /**
     * Create OTP SMS instance
     */
    public function otp(string $phone, string $message): static;

    /**
     * Create Pattern SMS instance
     *
     * @param  string|list<string>  $phones
     * @param  array<mixed>  $variables
     */
    public function pattern(string|array $phones, string $patternCode, array $variables): static;

    /**
     * Create regular text SMS instance
     *
     * @param  string|list<string>  $phones
     */
    public function text(string|array $phones, string $message): static;

    /**
     * Set the sender number for the SMS
     */
    public function from(string $from): static;

    /**
     * Send the SMS
     */
    public function send(): static;

    /**
     * Specify whether to log all SMS types
     */
    public function log(bool $log = true): static;

    /**
     * Specify whether to log OTP messages
     */
    public function logOtp(bool $log = true): static;

    /**
     * Specify whether to log pattern messages
     */
    public function logPattern(bool $log = true): static;

    /**
     * Specify whether to log text messages
     */
    public function logText(bool $log = true): static;

    /**
     * Log only successful SMS messages
     */
    public function logSuccessful(): static;

    /**
     * Log only failed SMS messages
     */
    public function logFailed(): static;

    /**
     * Check if SMS sending was successful
     */
    public function successful(): bool;

    /**
     * Check if SMS sending failed
     */
    public function failed(): bool;

    /**
     * Get the error message if SMS sending failed
     */
    public function error(): ?string;

    // ==================== متدهای مدیریت گروه (Phonebook Groups) ====================

    /**
     * Create a new group
     * 
     * @param string $name Group name
     * @param string|null $description Group description (optional)
     * @return array{success: bool, group_id: string|null, message: string}
     */
    public function createGroup(string $name, ?string $description = null): array;

    /**
     * Edit an existing group
     * 
     * @param string $groupId Group ID
     * @param string $name New group name
     * @param string|null $description New group description (optional)
     * @return array{success: bool, message: string}
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array;

    /**
     * Delete a group
     * 
     * @param string $groupId Group ID
     * @return array{success: bool, message: string}
     */
    public function deleteGroup(string $groupId): array;

    /**
     * Get all groups list
     * 
     * @return array{success: bool, groups: array, message: string}
     */
    public function getGroups(): array;

    // ==================== متدهای مدیریت مخاطب (Phonebook Contacts) ====================

    /**
     * Add a new contact
     * 
     * @param array{
     *     first_name?: string,
     *     last_name?: string,
     *     mobile: string,
     *     email?: string,
     *     group_id: string,
     *     corporation?: string,
     *     phone?: string,
     *     address?: string,
     *     gender?: int
     * } $contact Contact data
     * @return array{success: bool, contact_id: string|null, message: string}
     */
    public function addContact(array $contact): array;

    /**
     * Get contacts list
     * 
     * @param string|null $groupId Filter by group ID (optional)
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array{success: bool, contacts: array, total: int, message: string}
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array;

    /**
     * Delete a contact
     * 
     * @param string $contactId Contact ID
     * @return array{success: bool, message: string}
     */
    public function deleteContact(string $contactId): array;

    /**
     * Get contacts count in a group
     * 
     * @param string $groupId Group ID
     * @return array{success: bool, count: int, message: string}
     */
    public function getContactsCount(string $groupId): array;

    /**
     * Send SMS to a specific group
     * 
     * @param string $groupId Group ID
     * @param string $message Message content
     * @param string|null $from Sender number (optional)
     * @return array{success: bool, message_id: string|null, success_count: int, error?: string}
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array;
}