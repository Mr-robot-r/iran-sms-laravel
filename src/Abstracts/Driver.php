<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Abstracts;

use Mastertek\IranSms\Concerns\HasLog;
use Mastertek\IranSms\Contracts\Sms;
use Mastertek\IranSms\Enums\Type;
use Mastertek\IranSms\Exceptions\SmsContentNotDefinedException;
use Mastertek\IranSms\Exceptions\SmsIsImmutableException;
use Mastertek\IranSms\Exceptions\SmsNotSentYetException;

/**
 * @internal
 *
 * Base implementation of the public API and common foundation for all drivers.
 */
abstract class Driver implements Sms
{
    use HasLog;

    /**
     * Sms Type
     */
    private Type $type;

    /**
     * User defined sender number
     */
    private string $from;

    /**
     * Phone(s) to send message to
     *
     * @var string|list<string>
     */
    private string|array $phones;

    /**
     * Message content or variables for pattern
     *
     * @var string|array<mixed>
     */
    private string|array $content;

    /**
     * Message pattern code
     */
    private string $patternCode;

    /**
     * Whether SMS is sent
     */
    private bool $isSent = false;

    /**
     * Message ID for tracking (optional)
     */
    private ?string $messageId = null;

    /**
     * Success count for bulk sending
     */
    private int $successCount = 0;

    // ==================== متدهای انتزاعی اصلی ====================

    /**
     * {@inheritdoc}
     */
    abstract public function credit(): int;

    /**
     * Get the default sender number from config
     */
    abstract protected function getDefaultSender(): string;

    /**
     * Send OTP SMS
     */
    abstract protected function sendOtp(string $phone, string $message, string $from): static;

    /**
     * Send pattern SMS
     *
     * @param  list<string>  $phones
     * @param  array<mixed>  $variables
     */
    abstract protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static;

    /**
     * Send regular text SMS
     *
     * @param  list<string>  $phones
     */
    abstract protected function sendText(array $phones, string $message, string $from): static;

    /**
     * Check if SMS sending was successful
     */
    abstract protected function isSuccessful(): bool;

    /**
     * Get the error message if SMS sending failed
     */
    abstract protected function getErrorMessage(): string;

    /**
     * Get the error code if SMS sending failed
     */
    abstract protected function getErrorCode(): string|int;

    // ==================== متدهای جدید مدیریت گروه و مخاطب ====================

    /**
     * Create a new group
     * 
     * @param string $name Group name
     * @param string|null $description Group description (optional)
     * @return array{success: bool, group_id: string|null, message: string}
     */
    abstract public function createGroup(string $name, ?string $description = null): array;

    /**
     * Edit an existing group
     * 
     * @param string $groupId Group ID
     * @param string $name New group name
     * @param string|null $description New group description (optional)
     * @return array{success: bool, message: string}
     */
    abstract public function editGroup(string $groupId, string $name, ?string $description = null): array;

    /**
     * Delete a group
     * 
     * @param string $groupId Group ID
     * @return array{success: bool, message: string}
     */
    abstract public function deleteGroup(string $groupId): array;

    /**
     * Get all groups list
     * 
     * @return array{success: bool, groups: array, message: string}
     */
    abstract public function getGroups(): array;

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
    abstract public function addContact(array $contact): array;

    /**
     * Get contacts list
     * 
     * @param string|null $groupId Filter by group ID (optional)
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array{success: bool, contacts: array, total: int, message: string}
     */
    abstract public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array;

    /**
     * Delete a contact
     * 
     * @param string $contactId Contact ID
     * @return array{success: bool, message: string}
     */
    abstract public function deleteContact(string $contactId): array;

    /**
     * Get contacts count in a group
     * 
     * @param string $groupId Group ID
     * @return array{success: bool, count: int, message: string}
     */
    abstract public function getContactsCount(string $groupId): array;

    /**
     * Send SMS to a specific group
     * 
     * @param string $groupId Group ID
     * @param string $message Message content
     * @param string|null $from Sender number (optional)
     * @return array{success: bool, message_id: string|null, success_count: int, error?: string}
     */
    abstract public function sendToGroup(string $groupId, string $message, ?string $from = null): array;

    // ==================== متدهای کمکی (اختیاری) ====================

    /**
     * Set message ID (called by drivers)
     */
    protected function setMessageId(?string $id): static
    {
        $this->messageId = $id;
        return $this;
    }

    /**
     * Set success count (called by drivers)
     */
    protected function setSuccessCount(int $count): static
    {
        $this->successCount = $count;
        return $this;
    }

    /**
     * Get message ID after sending
     */
    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * Get success count after bulk sending
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * Get full result array
     * 
     * @return array{success: bool, message_id: string|null, success_count: int, error: string|null}
     */
    public function getResult(): array
    {
        return [
            'success' => $this->isSuccessful(),
            'message_id' => $this->getMessageId(),
            'success_count' => $this->getSuccessCount(),
            'error' => $this->error(),
        ];
    }

    // ==================== متدهای قبلی (دست نخورده) ====================

    /**
     * {@inheritdoc}
     *
     * @throws SmsIsImmutableException
     */
    final public function otp(string $phone, string $message): static
    {
        $this->checkSmsIsNotSet();

        $this->type = Type::Otp;

        $this->phones = $phone;
        $this->content = $message;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsIsImmutableException
     */
    final public function pattern(string|array $phones, string $patternCode, array $variables): static
    {
        $this->checkSmsIsNotSet();

        $this->type = Type::Pattern;

        $this->phones = $this->ensureIsArray($phones);
        $this->patternCode = $patternCode;
        $this->content = $variables;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsIsImmutableException
     */
    final public function text(string|array $phones, string $message): static
    {
        $this->checkSmsIsNotSet();

        $this->type = Type::Text;

        $this->phones = $this->ensureIsArray($phones);
        $this->content = $message;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function from(string $from): static
    {
        $this->from = $from;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsContentNotDefinedException
     */
    final public function send(): static
    {
        if (!isset($this->type)) {
            throw new SmsContentNotDefinedException('Before sending an SMS you must define its content by one of these methods "otp, pattern, text".');
        }

        match ($this->type) {
            Type::Otp => $this->sendOtp($this->phones, $this->content, $this->getSender()),
            Type::Pattern => $this->sendPattern($this->phones, $this->patternCode, $this->content, $this->getSender()),
            Type::Text => $this->sendText($this->phones, $this->content, $this->getSender()),
        };

        $this->isSent = true;

        $this->handleLog();

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsNotSentYetException
     */
    final public function successful(): bool
    {
        $this->checkSmsIsSent();

        return $this->isSuccessful();
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsNotSentYetException
     */
    final public function failed(): bool
    {
        $this->checkSmsIsSent();

        return !$this->isSuccessful();
    }

    /**
     * {@inheritdoc}
     *
     * @throws SmsNotSentYetException
     */
    final public function error(): ?string
    {
        $this->checkSmsIsSent();

        if ($this->isSuccessful()) {
            return null;
        }

        return sprintf('Code %s - %s', $this->getErrorCode(), $this->getErrorMessage());
    }

    /**
     * Get sender number to send SMS
     */
    private function getSender(): string
    {
        return $this->from ?? $this->getDefaultSender();
    }

    /**
     * Throw an exception if SMS content is set before
     *
     * @throws SmsIsImmutableException
     */
    private function checkSmsIsNotSet(): void
    {
        if (isset($this->type)) {
            throw new SmsIsImmutableException('SMS object is immutable, to create new SMS content you need to create new instance.');
        }
    }

    /**
     * Wrap data to array if it's not
     *
     * @return list<string>
     */
    private function ensureIsArray(mixed $data): array
    {
        return is_array($data) ? $data : [$data];
    }

    /**
     * Throw an exception if SMS is not sent yet
     *
     * @throws SmsNotSentYetException
     */
    private function checkSmsIsSent(): void
    {
        if (!$this->isSent) {
            throw new SmsNotSentYetException('To check SMS status, you first must send it with "send".');
        }
    }
}