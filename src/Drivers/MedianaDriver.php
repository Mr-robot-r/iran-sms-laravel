<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\InvalidPatternStructureException;
use Mastertek\IranSms\Exceptions\UnsupportedMethodException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * @see https://docs.ippanel.com/docs
 */
final class MedianaDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://edge.ippanel.com/v1/api/';

    /**
     * The sent status returned in the API response body (e.g., `meta.status` field).
     */
    private bool $apiStatus;

    /**
     * The status code returned in the API response body (e.g., `meta.message_code` field).
     */
    private string $apiStatusCode;

    /**
     * The error message returned in the API response body (e.g., `meta.message` field).
     */
    private string $apiErrorMessage;

    /**
     * The data returned from API
     */
    private array $apiData;

    /**
     * Pagination meta data
     */
    private array $apiMeta;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {
    }

    // ==================== متدهای اجباری کلاس Driver ====================

    /**
     * {@inheritdoc}
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('payment/credit/mine')
            ->throw();

        $this->processResponse($response);

        return (int) $response->json('data.credit');
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
        $this->validatePatternVariables($variables);

        $data = [
            'sending_type' => 'pattern',
            'from_number' => $from,
            'code' => $patternCode,
            'recipients' => $phones,
            'params' => $variables,
        ];

        $this->execute('send', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'sending_type' => 'webservice',
            'from_number' => $from,
            'message' => $message,
            'params' => [
                'recipients' => $phones,
            ],
        ];

        $this->execute('send', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatus;
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
        return $this->apiStatusCode;
    }

    // ==================== متدهای مدیریت گروه ====================

    /**
     * {@inheritdoc}
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        $data = ['title' => $name];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phonebooks', $data)
            ->throw();

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
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        $data = ['title' => $name];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->put('phonebooks/' . $groupId, $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'گروه با موفقیت ویرایش شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function deleteGroup(string $groupId): array
    {
        $data = ['listPhonebooks' => [(int) $groupId]];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phonebooks/delete-list', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'گروه با موفقیت حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getGroups(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phonebooks/list-new', ['page' => 1, 'per_page' => 1000])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $groupsData = $this->apiData ?? [];
            $groups = array_map(function ($item) {
                return [
                    'GroupID' => (string) ($item['id'] ?? ''),
                    'Name' => $item['title'] ?? '',
                    'Description' => '',
                    'IsActive' => true,
                ];
            }, $groupsData);

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

    // ==================== متدهای مدیریت مخاطب ====================

    /**
     * {@inheritdoc}
     */
    public function addContact(array $contact): array
    {
        $data = [
            'list' => [
                [
                    'phonebook_id' => $contact['group_id'],
                    'number' => $contact['mobile'],
                    'pre' => $contact['first_name'] ?? '',
                    'name' => $contact['last_name'] ?? '',
                    'email' => $contact['email'] ?? '',
                ],
            ],
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phonebooks/numbers/add-list-new', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact_id' => null, // مدیانا contact_id برنمی‌گرداند
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
     * {@inheritdoc}
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $queryParams = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($groupId) {
            $queryParams['phonebook_id'] = $groupId;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phonebooks/numbers/contact-list', $queryParams)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $contactsData = $this->apiData ?? [];
            $total = $this->apiMeta['total'] ?? count($contactsData);

            $contacts = array_map(function ($item) {
                return [
                    'ContactID' => (string) ($item['id'] ?? ''),
                    'FirstName' => $item['pre'] ?? '',
                    'LastName' => $item['name'] ?? '',
                    'MobileNumbers' => $item['number'] ?? '',
                    'Email' => $item['email'] ?? '',
                    'GroupID' => (string) ($item['phonebook_id'] ?? ''),
                ];
            }, $contactsData);

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
     * {@inheritdoc}
     */
    public function deleteContact(string $contactId): array
    {
        $data = ['listNumbers' => [(int) $contactId]];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('phonebooks/numbers/delete-list', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getContactsCount(string $groupId): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phonebooks/list-new', ['id' => $groupId, 'page' => 1, 'per_page' => 1])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $groupsData = $this->apiData ?? [];
            $count = 0;

            foreach ($groupsData as $group) {
                if ((string) ($group['id'] ?? '') === $groupId) {
                    $count = $group['count'] ?? 0;
                    break;
                }
            }

            return [
                'success' => true,
                'count' => $count,
                'message' => 'تعداد مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'count' => 0,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        $params = [
            [
                'phonebook_ids' => [$groupId],
                'type' => 'all',
            ],
        ];

        $data = [
            'sending_type' => 'phonebook',
            'from_number' => $from ?? $this->from,
            'message' => $message,
            'params' => $params,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('send', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $messageIds = $this->apiData['message_outbox_ids'] ?? [];
            $this->setMessageId(implode(',', $messageIds));
            $this->setSuccessCount(count($messageIds));

            return [
                'success' => true,
                'message_id' => $this->getMessageId(),
                'success_count' => $this->getSuccessCount(),
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'message_id' => null,
            'success_count' => 0,
            'error' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای اضافی مدیانا (اختیاری) ====================

    /**
     * دریافت لیست گروه‌ها با صفحه‌بندی (نسخه پیشرفته)
     */
    public function getGroupsPaged(int $page = 1, int $perPage = 10, ?string $title = null, ?int $id = null): array
    {
        $queryParams = [
            'page' => $page,
            'per_page' => min($perPage, 1000),
        ];

        if ($title) {
            $queryParams['title'] = $title;
        }
        if ($id) {
            $queryParams['id'] = $id;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phonebooks/list-new', $queryParams)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'groups' => $this->apiData,
                'meta' => $this->apiMeta,
                'message' => 'لیست گروه‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'groups' => [],
            'meta' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست مخاطبین با صفحه‌بندی (نسخه پیشرفته)
     */
    public function getContactsPaged(
        ?int $phonebookId = null,
        ?string $phonebookTitle = null,
        ?string $number = null,
        ?int $fromCreatedAt = null,
        int $page = 1,
        int $perPage = 10
    ): array {
        $queryParams = [
            'page' => $page,
            'per_page' => min($perPage, 1000),
        ];

        if ($phonebookId) {
            $queryParams['phonebook_id'] = $phonebookId;
        }
        if ($phonebookTitle) {
            $queryParams['phonebook_title'] = $phonebookTitle;
        }
        if ($number) {
            $queryParams['number'] = $number;
        }
        if ($fromCreatedAt) {
            $queryParams['from_created_at'] = $fromCreatedAt;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('phonebooks/numbers/contact-list', $queryParams)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contacts' => $this->apiData,
                'meta' => $this->apiMeta,
                'message' => 'لیست مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'contacts' => [],
            'meta' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * محاسبه قیمت پیامک
     */
    public function calculatePrice(string $number, string $message): array
    {
        $data = [
            'number' => $number,
            'message' => $message,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('send/calculate-price', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'mci_price' => $this->apiData['mci_price'] ?? 0,
                'other_price' => $this->apiData['other_price'] ?? 0,
                'parts' => $this->apiData['parts'] ?? 1,
                'message' => 'قیمت با موفقیت محاسبه شد',
            ];
        }

        return [
            'success' => false,
            'mci_price' => 0,
            'other_price' => 0,
            'parts' => 0,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * لغو پیام زمانبندی شده
     */
    public function cancelScheduledMessage(int $messageOutboxId): array
    {
        $data = ['message_outbox_id' => $messageOutboxId];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('send/cancel', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت وضعیت تحویل پیامک
     */
    public function getDeliveryStatus(int $messageId): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('send/message/' . $messageId)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'status' => $this->apiData['status'] ?? null,
                'message' => 'وضعیت پیامک دریافت شد',
            ];
        }

        return [
            'success' => false,
            'status' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی
     */
    public function getInbox(?string $lineNumber = null, int $page = 1, int $perPage = 50): array
    {
        $queryParams = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($lineNumber) {
            $queryParams['line_number'] = $lineNumber;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->get('inbox', $queryParams)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'messages' => $this->apiData['messages'] ?? [],
                'total' => $this->apiData['total'] ?? 0,
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
            ->throw();

        $this->processResponse($response);
    }

    /**
     * Process API response
     */
    private function processResponse($response): void
    {
        $meta = $response->json('meta');

        $this->apiStatus = $meta['status'] ?? false;
        $this->apiStatusCode = $meta['message_code'] ?? '';
        $this->apiErrorMessage = $meta['message'] ?? '';
        $this->apiData = $response->json('data') ?? [];

        if (isset($this->apiData['meta'])) {
            $this->apiMeta = $this->apiData['meta'];
            unset($this->apiData['meta']);
        } else {
            $this->apiMeta = [];
        }

        if ($this->isSuccessful()) {
            if (isset($this->apiData['message_outbox_ids']) && is_array($this->apiData['message_outbox_ids'])) {
                $this->setMessageId(implode(',', $this->apiData['message_outbox_ids']));
                $this->setSuccessCount(count($this->apiData['message_outbox_ids']));
            } elseif (isset($this->apiData['message_id'])) {
                $this->setMessageId((string) $this->apiData['message_id']);
                $this->setSuccessCount(1);
            }
        }
    }

    /**
     * Get driver name for exceptions
     */
    protected function getDriverName(): string
    {
        return 'Mediana';
    }

    /**
     * @return array{Authorization: string}
     */
    private function credentials(): array
    {
        return [
            'Authorization' => $this->token,
        ];
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