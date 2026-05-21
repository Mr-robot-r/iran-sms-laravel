<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Dtos\MockResponse;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * Fake SMS driver used to simulate sending behavior during tests.
 */
final class FakeDriver extends Driver
{
    /**
     * ذخیره گروه‌های ایجاد شده در حین تست
     */
    private array $fakeGroups = [];

    /**
     * ذخیره مخاطبین ایجاد شده در حین تست
     */
    private array $fakeContacts = [];

    /**
     * شمارنده برای تولید ID خودکار
     */
    private int $nextGroupId = 1;
    private int $nextContactId = 1;

    public function __construct(private readonly MockResponse $response)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function credit(): int
    {
        return 1000000; // اعتبار تستی
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultSender(): string
    {
        return '3000xxxx';
    }

    /**
     * {@inheritdoc}
     */
    protected function sendOtp(string $phone, string $message, string $from): static
    {
        $this->fakeHttpExceptionIfRequired();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->fakeHttpExceptionIfRequired();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $this->fakeHttpExceptionIfRequired();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->response->isSuccessful();
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorMessage(): string
    {
        return $this->response->errorMessage();
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->response->errorCode();
    }

    // ==================== متدهای مدیریت گروه (Fake) ====================

    /**
     * {@inheritdoc}
     * ایجاد گروه تستی
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'group_id' => null,
                'message' => $this->getErrorMessage(),
            ];
        }

        $groupId = (string) $this->nextGroupId++;

        $this->fakeGroups[$groupId] = [
            'id' => $groupId,
            'name' => $name,
            'description' => $description ?? '',
            'created_at' => now()->toDateTimeString(),
        ];

        return [
            'success' => true,
            'group_id' => $groupId,
            'message' => 'گروه با موفقیت ایجاد شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * ویرایش گروه تستی
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'message' => $this->getErrorMessage(),
            ];
        }

        if (!isset($this->fakeGroups[$groupId])) {
            return [
                'success' => false,
                'message' => 'گروه مورد نظر یافت نشد',
            ];
        }

        $this->fakeGroups[$groupId]['name'] = $name;
        $this->fakeGroups[$groupId]['description'] = $description ?? '';

        return [
            'success' => true,
            'message' => 'گروه با موفقیت ویرایش شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * حذف گروه تستی
     */
    public function deleteGroup(string $groupId): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'message' => $this->getErrorMessage(),
            ];
        }

        if (!isset($this->fakeGroups[$groupId])) {
            return [
                'success' => false,
                'message' => 'گروه مورد نظر یافت نشد',
            ];
        }

        // حذف مخاطبین مرتبط با گروه
        foreach ($this->fakeContacts as $contactId => $contact) {
            if (($contact['GroupID'] ?? '') === $groupId) {
                unset($this->fakeContacts[$contactId]);
            }
        }

        unset($this->fakeGroups[$groupId]);

        return [
            'success' => true,
            'message' => 'گروه با موفقیت حذف شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * دریافت لیست گروه‌های تستی
     */
    public function getGroups(): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'groups' => [],
                'message' => $this->getErrorMessage(),
            ];
        }

        $groups = array_values(array_map(function ($group) {
            return [
                'GroupID' => $group['id'],
                'Name' => $group['name'],
                'Description' => $group['description'],
                'IsActive' => true,
                'MemberCount' => $this->getGroupMemberCount($group['id']),
                'CreatedAt' => $group['created_at'],
            ];
        }, $this->fakeGroups));

        return [
            'success' => true,
            'groups' => $groups,
            'message' => 'لیست گروه‌ها دریافت شد (Fake)',
        ];
    }

    // ==================== متدهای مدیریت مخاطب (Fake) ====================

    /**
     * {@inheritdoc}
     * اضافه کردن مخاطب تستی
     */
    public function addContact(array $contact): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'contact_id' => null,
                'message' => $this->getErrorMessage(),
            ];
        }

        if (empty($contact['mobile'])) {
            return [
                'success' => false,
                'contact_id' => null,
                'message' => 'شماره موبایل الزامی است',
            ];
        }

        if (empty($contact['group_id'])) {
            return [
                'success' => false,
                'contact_id' => null,
                'message' => 'شناسه گروه الزامی است',
            ];
        }

        if (!isset($this->fakeGroups[$contact['group_id']])) {
            return [
                'success' => false,
                'contact_id' => null,
                'message' => 'گروه مورد نظر یافت نشد',
            ];
        }

        $contactId = (string) $this->nextContactId++;

        $this->fakeContacts[$contactId] = [
            'id' => $contactId,
            'group_id' => $contact['group_id'],
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'mobile' => $contact['mobile'],
            'email' => $contact['email'] ?? '',
            'created_at' => now()->toDateTimeString(),
        ];

        return [
            'success' => true,
            'contact_id' => $contactId,
            'message' => 'مخاطب با موفقیت اضافه شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * دریافت لیست مخاطبین تستی
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'contacts' => [],
                'total' => 0,
                'message' => $this->getErrorMessage(),
            ];
        }

        $contacts = array_values(array_map(function ($contact) {
            return [
                'ContactID' => $contact['id'],
                'FirstName' => $contact['first_name'],
                'LastName' => $contact['last_name'],
                'MobileNumbers' => $contact['mobile'],
                'Email' => $contact['email'],
                'GroupID' => $contact['group_id'],
                'CreatedAt' => $contact['created_at'],
            ];
        }, $this->fakeContacts));

        // فیلتر بر اساس گروه
        if ($groupId) {
            $contacts = array_filter($contacts, function ($contact) use ($groupId) {
                return $contact['GroupID'] === $groupId;
            });
            $contacts = array_values($contacts);
        }

        // صفحه‌بندی
        $total = count($contacts);
        $offset = ($page - 1) * $perPage;
        $pagedContacts = array_slice($contacts, $offset, $perPage);

        return [
            'success' => true,
            'contacts' => $pagedContacts,
            'total' => $total,
            'message' => 'لیست مخاطبین دریافت شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * حذف مخاطب تستی
     */
    public function deleteContact(string $contactId): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'message' => $this->getErrorMessage(),
            ];
        }

        if (!isset($this->fakeContacts[$contactId])) {
            return [
                'success' => false,
                'message' => 'مخاطب مورد نظر یافت نشد',
            ];
        }

        unset($this->fakeContacts[$contactId]);

        return [
            'success' => true,
            'message' => 'مخاطب با موفقیت حذف شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * دریافت تعداد مخاطبین گروه تستی
     */
    public function getContactsCount(string $groupId): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'count' => 0,
                'message' => $this->getErrorMessage(),
            ];
        }

        $count = 0;
        foreach ($this->fakeContacts as $contact) {
            if (($contact['group_id'] ?? '') === $groupId) {
                $count++;
            }
        }

        return [
            'success' => true,
            'count' => $count,
            'message' => 'تعداد مخاطبین دریافت شد (Fake)',
        ];
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک به گروه تستی
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        $this->fakeHttpExceptionIfRequired();

        if (!$this->isSuccessful()) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => $this->getErrorMessage(),
            ];
        }

        if (!isset($this->fakeGroups[$groupId])) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => 'گروه مورد نظر یافت نشد',
            ];
        }

        $contacts = $this->getContacts($groupId);

        if (!$contacts['success'] || empty($contacts['contacts'])) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => 'هیچ مخاطبی در این گروه وجود ندارد',
            ];
        }

        $successCount = count($contacts['contacts']);
        $messageId = (string) rand(100000, 999999);

        $this->setMessageId($messageId);
        $this->setSuccessCount($successCount);

        return [
            'success' => true,
            'message_id' => $messageId,
            'success_count' => $successCount,
            'error' => null,
        ];
    }

    // ==================== متدهای کمکی برای تست ====================

    /**
     * دریافت تعداد اعضای گروه
     */
    private function getGroupMemberCount(string $groupId): int
    {
        $count = 0;
        foreach ($this->fakeContacts as $contact) {
            if (($contact['group_id'] ?? '') === $groupId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * ریست کردن داده‌های تست (برای استفاده در setUp تست‌ها)
     */
    public function resetFakeData(): void
    {
        $this->fakeGroups = [];
        $this->fakeContacts = [];
        $this->nextGroupId = 1;
        $this->nextContactId = 1;
    }

    /**
     * دریافت تمام گروه‌های تست (برای بررسی در تست‌ها)
     */
    public function getFakeGroups(): array
    {
        return $this->fakeGroups;
    }

    /**
     * دریافت تمام مخاطبین تست (برای بررسی در تست‌ها)
     */
    public function getFakeContacts(): array
    {
        return $this->fakeContacts;
    }

    /**
     * Throw a connection exception if defined by the user.
     */
    private function fakeHttpExceptionIfRequired(): void
    {
        if ($this->response->shouldThrow()) {
            Http::fake([
                'sms/fake/driver' => Http::failedConnection(),
            ]);

            Http::get('sms/fake/driver');
        }
    }
}