<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\InvalidPatternStructureException;
use Mastertek\IranSms\Exceptions\UnsupportedMethodException;
use Mastertek\IranSms\Exceptions\UnsupportedMultiplePhonesException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * @see https://docs.iranpayamak.com/ or https://docs2.farazsms.com/
 */
final class FarazSmsDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://api.iranpayamak.com/ws/v1';

    /**
     * The sent status returned in the API response body (e.g., `status` field).
     */
    private string $apiStatus;

    /**
     * The error message returned in the API response body (e.g., `messages` field).
     */
    private string $apiErrorMessage;

    /**
     * The data returned from API
     */
    private array $apiData;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('account/balance')
            ->throwIfServerError();

        $this->processResponse($response);

        return (int) $response->json('data.balanceAmount');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultSender(): string
    {
        return $this->from;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnsupportedMethodException
     */
    protected function sendOtp(string $phone, string $message, string $from): static
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'otp', alternative: 'pattern');
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidPatternStructureException
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = [
            'number_format' => 'english',
            'line_number' => $from,
            'code' => $patternCode,
            'recipient' => $phones[0],
            'attributes' => $variables,
            'schedule' => null,
        ];

        $this->execute('sms/pattern', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'number_format' => 'english',
            'line_number' => $from,
            'text' => $message,
            'recipients' => $phones,
            'schedule' => null,
        ];

        $this->execute('sms/simple', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatus === 'success';
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorMessage(): string
    {
        return $this->apiErrorMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->apiStatus === 'success' ? 200 : 400;
    }

    // ==================== مدیریت گروه (Phonebook) ====================

    /**
     * {@inheritdoc}
     * دریافت لیست دفترچه‌های تلفن (گروه‌ها)
     * GET /phone_book
     */
    public function getGroups(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phone_book')
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $data = $this->apiData['data'] ?? [];
            $groups = array_map(function ($item) {
                return [
                    'GroupID' => $item['id'] ?? null,
                    'Name' => $item['name'] ?? '',
                    'Description' => $item['description'] ?? '',
                    'IsActive' => true,
                    'CreatedAt' => $item['created_at'] ?? null,
                ];
            }, $data);

            return [
                'success' => true,
                'groups' => $groups,
                'message' => 'لیست گروه‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'groups' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     * ایجاد گروه جدید
     * POST /phone_book
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        $data = [
            'name' => $name,
            'description' => $description ?? '',
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phone_book', $data)
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'group_id' => (string) ($this->apiData['id'] ?? ''),
                'message' => 'گروه با موفقیت ایجاد شد',
            ];
        }

        return [
            'success' => false,
            'group_id' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     * ویرایش گروه
     * PUT /phone_book/{id}
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        $data = [
            'name' => $name,
            'description' => $description ?? '',
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->put("phone_book/{$groupId}", $data)
            ->throwIfServerError();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'گروه با موفقیت ویرایش شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     * حذف گروه (مستندات فراز اس ام اس متد حذف گروه ندارد)
     * 
     * @throws UnsupportedMethodException
     */
    public function deleteGroup(string $groupId): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'deleteGroup');
    }

    // ==================== مدیریت مخاطب (Phonebook Data) ====================

    /**
     * دریافت لیست مخاطبین
     * GET /phone_book_data
     * 
     * @param string|null $groupId شناسه گروه (اختیاری)
     * @param int $page شماره صفحه
     * @param int $perPage تعداد در هر صفحه
     * @return array{success: bool, contacts: array, total: int, message: string}
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $queryParams = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($groupId) {
            $queryParams['phone_book_id'] = $groupId;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phone_book_data', $queryParams)
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $data = $this->apiData['data'] ?? [];
            $total = $this->apiData['total'] ?? count($data);

            $contacts = array_map(function ($item) {
                return [
                    'ContactID' => $item['id'] ?? null,
                    'FirstName' => $item['first_name'] ?? '',
                    'LastName' => $item['last_name'] ?? '',
                    'MobileNumbers' => $item['mobile'] ?? '',
                    'Email' => $item['email'] ?? '',
                    'GroupID' => $item['phone_book_id'] ?? '',
                    'CreatedAt' => $item['created_at'] ?? null,
                ];
            }, $data);

            return [
                'success' => true,
                'contacts' => $contacts,
                'total' => $total,
                'message' => 'لیست مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'contacts' => [],
            'total' => 0,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * اضافه کردن مخاطب جدید
     * POST /phone_book_data
     * 
     * @param array{
     *     first_name?: string,
     *     last_name?: string,
     *     mobile: string,
     *     email?: string,
     *     group_id: string
     * } $contact
     * @return array{success: bool, contact_id: string|null, message: string}
     */
    public function addContact(array $contact): array
    {
        $data = [
            'phone_book_id' => $contact['group_id'],
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'mobile' => $contact['mobile'],
            'email' => $contact['email'] ?? '',
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phone_book_data', $data)
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact_id' => (string) ($this->apiData['id'] ?? ''),
                'message' => 'مخاطب با موفقیت اضافه شد',
            ];
        }

        return [
            'success' => false,
            'contact_id' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * حذف مخاطب
     * DELETE /phone_book_data/{id}
     * 
     * @param string $contactId شناسه مخاطب
     * @return array{success: bool, message: string}
     */
    public function deleteContact(string $contactId): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->delete("phone_book_data/{$contactId}")
            ->throwIfServerError();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت تعداد مخاطبین گروه
     * 
     * @param string $groupId شناسه گروه
     * @return array{success: bool, count: int, message: string}
     */
    public function getContactsCount(string $groupId): array
    {
        $contacts = $this->getContacts($groupId, 1, 1);

        if ($contacts['success']) {
            return [
                'success' => true,
                'count' => $contacts['total'],
                'message' => 'تعداد مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'count' => 0,
            'message' => $contacts['message'],
        ];
    }

    /**
     * ارسال پیامک به گروه
     * 
     * @param string $groupId شناسه گروه
     * @param string $message متن پیامک
     * @param string|null $from شماره فرستنده (اختیاری)
     * @return array{success: bool, message_id: string|null, success_count: int, error?: string}
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        $contacts = $this->getContacts($groupId, 1, 1000);

        if (!$contacts['success']) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => $contacts['message'],
            ];
        }

        $mobiles = [];
        foreach ($contacts['contacts'] as $contact) {
            if (!empty($contact['MobileNumbers'])) {
                $mobiles[] = $contact['MobileNumbers'];
            }
        }

        if (empty($mobiles)) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => 'هیچ مخاطبی در این گروه وجود ندارد',
            ];
        }

        $result = $this->sendText($mobiles, $message, $from ?? $this->from);

        return [
            'success' => $result->isSuccessful(),
            'message_id' => $result->getMessageId(),
            'success_count' => $result->getSuccessCount(),
            'error' => $result->getErrorMessage(),
        ];
    }

    // ==================== متدهای اضافی مفید ====================

    /**
     * دریافت اطلاعات پروفایل کاربر
     * GET /account/profile
     * 
     * @return array{success: bool, data: array, message: string}
     */
    public function getProfile(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('account/profile')
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'data' => $this->apiData,
                'message' => 'اطلاعات پروفایل دریافت شد',
            ];
        }

        return [
            'success' => false,
            'data' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست خطوط قابل دسترسی
     * GET /lines/accessible
     * 
     * @return array{success: bool, lines: array, message: string}
     */
    public function getLines(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('lines/accessible')
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'lines' => $this->apiData['data'] ?? [],
                'message' => 'لیست خطوط دریافت شد',
            ];
        }

        return [
            'success' => false,
            'lines' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست الگوها
     * GET /patterns
     * 
     * @return array{success: bool, patterns: array, message: string}
     */
    public function getPatterns(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('patterns')
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'patterns' => $this->apiData['data'] ?? [],
                'message' => 'لیست الگوها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'patterns' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی
     * GET /inbox
     * 
     * @param int $page شماره صفحه
     * @param int $perPage تعداد در هر صفحه
     * @return array{success: bool, messages: array, total: int, message: string}
     */
    public function getInbox(int $page = 1, int $perPage = 50): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('inbox', [
                'page' => $page,
                'per_page' => $perPage,
            ])
            ->throwIfServerError();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $data = $this->apiData['data'] ?? [];
            return [
                'success' => true,
                'messages' => $data['data'] ?? [],
                'total' => $data['total'] ?? 0,
                'message' => 'پیامک‌های دریافتی دریافت شد',
            ];
        }

        return [
            'success' => false,
            'messages' => [],
            'total' => 0,
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای خصوصی ====================

    /**
     * Executes the API request to the specified endpoint with given data.
     *
     * @param  string  $endpoint
     * @param  array<string, mixed>  $data
     */
    private function execute(string $endpoint, array $data): void
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->post($endpoint, $data)
            ->throwIfServerError();

        $this->processResponse($response);
    }

    /**
     * Process API response
     */
    private function processResponse($response): void
    {
        $this->apiStatus = $response->json('status');
        $this->apiErrorMessage = (string) $response->json('messages');
        $this->apiData = $response->json('data') ?? [];

        if ($this->isSuccessful()) {
            // استخراج messageId از پاسخ (برای متدهای ارسال)
            if (isset($this->apiData['message_id'])) {
                $this->setMessageId((string) $this->apiData['message_id']);
            }

            // استخراج تعداد ارسال‌های موفق (برای ارسال گروهی)
            if (isset($this->apiData['success_count'])) {
                $this->setSuccessCount((int) $this->apiData['success_count']);
            } elseif (isset($this->apiData['recipients_count'])) {
                $this->setSuccessCount((int) $this->apiData['recipients_count']);
            }
        }
    }

    /**
     * @return array{Api-Key: string}
     */
    private function credentials(): array
    {
        return [
            'Api-Key' => $this->token,
        ];
    }

    /**
     * @param  array<string>  $phones
     *
     * @throws UnsupportedMultiplePhonesException
     */
    private function validatePatternPhones(array $phones): void
    {
        if (count($phones) !== 1) {
            throw UnsupportedMultiplePhonesException::make($this->getDriverName(), method: 'pattern');
        }
    }

    /**
     * @param  array<mixed>  $variables
     *
     * @throws InvalidPatternStructureException
     */
    private function validatePatternVariables(array $variables): void
    {
        if (Arr::isList($variables)) {
            throw new InvalidPatternStructureException(
                sprintf('Provider "%s" only accepts pattern data as key-value pairs.', $this->getDriverName())
            );
        }
    }
}