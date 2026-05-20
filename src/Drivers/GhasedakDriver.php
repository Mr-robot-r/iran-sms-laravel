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
 * @see https://ghasedak.me/docs
 */
final class GhasedakDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://gateway.ghasedak.me/rest/api/v1/WebService';

    /**
     * The sent status returned in the API response body (e.g., `IsSuccess` field).
     */
    private bool $apiStatus;

    /**
     * The status code returned in the API response body (e.g., `StatusCode` field).
     */
    private int $apiStatusCode;

    /**
     * The message returned in the API response body (e.g., `Message` field).
     */
    private string $apiMessage;

    /**
     * The data returned from API
     */
    private array $apiData;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {}

    /**
     * {@inheritdoc}
     * متد GetAccountInformation
     */
    public function credit(): int
    {
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('GetAccountInformation')
            ->throw();

        $this->processResponse($response);

        return (int) $response->json('Data.Credit');
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
    protected function sendOtp(string $phone, string $code, string $from): static
    {
        // قاصدک متد sendOtp دارد! با نام SendOtpSMS
        $data = [
            'receptors' => [
                [
                    'mobile' => $phone,
                    'clientReferenceId' => (string) time(),
                ]
            ],
            'templateName' => 'otp', // باید در پنل قاصدک تعریف شود
            'inputs' => [
                [
                    'param' => 'code',
                    'value' => $code,
                ]
            ],
            'udh' => false,
        ];

        $this->execute('SendOtpSMS', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidPatternStructureException
     * @throws UnsupportedMultiplePhonesException
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        // تبدیل متغیرها به فرمت مورد نیاز قاصدک
        $inputs = [];
        foreach ($variables as $param => $value) {
            $inputs[] = [
                'param' => $param,
                'value' => (string) $value,
            ];
        }

        $data = [
            'receptors' => [
                [
                    'mobile' => $phones[0],
                    'clientReferenceId' => (string) time(),
                ]
            ],
            'templateName' => $patternCode,
            'inputs' => $inputs,
            'udh' => false,
        ];

        $this->execute('SendOtpSMS', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال گروهی با متد SendBulkSMS
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'lineNumber' => $from,
            'message' => $message,
            'receptors' => $phones,
            'clientReferenceId' => (string) time(),
            'isVoice' => false,
            'udh' => false,
            'sendDate' => null,
        ];

        $this->execute('SendBulkSMS', $data);

        return $this;
    }

    /**
     * ارسال پیامک نظیر به نظیر (هر گیرنده متن جداگانه)
     * متد SendPairToPairSMS
     * 
     * @param array<array{receptor: string, message: string}> $items
     */
    public function sendPairToPair(array $items, string $from): static
    {
        $pairItems = [];
        foreach ($items as $item) {
            $pairItems[] = [
                'lineNumber' => $from,
                'receptor' => $item['receptor'],
                'message' => $item['message'],
                'clientReferenceId' => (string) time(),
                'sendDate' => null,
            ];
        }

        $data = [
            'items' => $pairItems,
            'udh' => false,
        ];

        $this->execute('SendPairToPairSMS', $data);

        return $this;
    }

    /**
     * دریافت وضعیت پیامک
     * متد CheckSmsStatus
     * 
     * @param string|array $ids شناسه پیامک‌ها
     * @param int $type 1=messageid, 2=checkid
     */
    public function checkStatus($ids, int $type = 1): array
    {
        $idString = is_array($ids) ? implode(',', $ids) : $ids;

        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('CheckSmsStatus', [
                'Ids' => $idString,
                'Type' => $type,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'items' => $this->apiData,
                'message' => 'وضعیت پیامک دریافت شد',
            ];
        }

        return [
            'success' => false,
            'items' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی (100 پیام آخر)
     * متد GetReceivedSmses
     * 
     * @param string $lineNumber شماره خط
     * @param bool $isRead true=خوانده شده, false=خوانده نشده
     */
    public function getReceivedMessages(string $lineNumber, bool $isRead = false): array
    {
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('GetReceivedSmses', [
                'LineNumber' => $lineNumber,
                'IsRead' => $isRead,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'items' => $this->apiData['Items'] ?? [],
                'message' => 'پیامک‌های دریافتی دریافت شد',
            ];
        }

        return [
            'success' => false,
            'items' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی با صفحه بندی
     * متد GetReceivedSmsesPaging
     * 
     * @param string $lineNumber شماره خط
     * @param bool $isRead true=خوانده شده, false=خوانده نشده
     * @param int $pageIndex شماره صفحه
     * @param int $pageSize تعداد در هر صفحه (حداکثر 200)
     * @param string|null $startDate تاریخ شروع
     * @param string|null $endDate تاریخ پایان
     */
    public function getReceivedMessagesPaging(
        string $lineNumber, 
        bool $isRead = false, 
        int $pageIndex = 1, 
        int $pageSize = 50,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $params = [
            'LineNumber' => $lineNumber,
            'IsRead' => $isRead,
            'PageIndex' => $pageIndex,
            'PageSize' => min($pageSize, 200),
        ];

        if ($startDate) {
            $params['StartDate'] = $startDate;
        }
        if ($endDate) {
            $params['EndDate'] = $endDate;
        }

        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('GetReceivedSmsesPaging', $params)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'pageIndex' => $this->apiData['pageIndex'] ?? 1,
                'pageSize' => $this->apiData['pageSize'] ?? 0,
                'totalCount' => $this->apiData['totalCount'] ?? 0,
                'totalPages' => $this->apiData['totalPages'] ?? 0,
                'hasNextPage' => $this->apiData['hasNextPage'] ?? false,
                'items' => $this->apiData['items'] ?? [],
                'message' => 'پیامک‌های دریافتی دریافت شد',
            ];
        }

        return [
            'success' => false,
            'items' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پارامترهای قالب OTP
     * متد GetOtpTemplateParameters
     * 
     * @param string $templateName نام قالب
     */
    public function getOtpTemplateParams(string $templateName): array
    {
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('GetOtpTemplateParameters', [
                'TemplateName' => $templateName,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'params' => $this->apiData['Params'] ?? [],
                'messageText' => $this->apiData['Message'] ?? '',
                'message' => 'پارامترهای قالب دریافت شد',
            ];
        }

        return [
            'success' => false,
            'params' => [],
            'messageText' => '',
            'message' => $this->getErrorMessage(),
        ];
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
        return $this->apiMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->apiStatusCode;
    }

    // ==================== متدهای غیرقابل پشتیبانی (گروه و مخاطب) ====================

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'createGroup');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'editGroup');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function deleteGroup(string $groupId): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'deleteGroup');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function getGroups(): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'getGroups');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function addContact(array $contact): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'addContact');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'getContacts');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function deleteContact(string $contactId): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'deleteContact');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function getContactsCount(string $groupId): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'getContactsCount');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'sendToGroup');
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
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->post($endpoint, $data)
            ->throw();

        $this->processResponse($response);
    }

    private function processResponse($response): void
    {
        $this->apiStatus = (bool) $response->json('IsSuccess');
        $this->apiStatusCode = (int) $response->json('StatusCode');
        $this->apiMessage = (string) $response->json('Message');
        $this->apiData = $response->json('Data') ?? [];

        if ($this->isSuccessful() && !empty($this->apiData)) {
            // استخراج messageId از پاسخ (برای متدهای ارسال)
            $items = $this->apiData['Items'] ?? [];
            if (!empty($items) && isset($items[0]['MessageId'])) {
                $this->setMessageId((string) $items[0]['MessageId']);
                $this->setSuccessCount(count($items));
            }
            
            // برای ارسال تکی
            if (isset($this->apiData['MessageId'])) {
                $this->setMessageId((string) $this->apiData['MessageId']);
                $this->setSuccessCount(1);
            }
        }
    }

    /**
     * @return array{ApiKey: string}
     */
    private function credentials(): array
    {
        return [
            'ApiKey' => $this->token,
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