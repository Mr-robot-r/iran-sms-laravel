<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\InvalidPatternStructureException;
use Mastertek\IranSms\Exceptions\UnsupportedMethodException;
use Mastertek\IranSms\Exceptions\UnsupportedMultiplePhonesException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * @see https://raygansms.com/webservice/api/home#samplecode
 */
final class RayganSmsDriver extends Driver
{
    /**
     * The URL for sending OTP message
     */
    private string $otpUrl = 'https://raygansms.com/SendMessageWithCode.ashx';

    /**
     * The base URL for REST API
     */
    private string $restBaseUrl = 'https://smspanel.trez.ir/api/';

    /**
     * Sending status based on the API response code (`$apiStatusCode`).
     */
    private bool $apiSuccess;

    /**
     * The status code returned in the API response body.
     */
    private int $apiStatusCode;

    /**
     * The error message returned in the API response body.
     */
    private string $apiErrorMessage;

    /**
     * The result data from API
     */
    private array $apiData;

    public function __construct(
        private readonly string $token,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    /**
     * {@inheritdoc}
     * دریافت اعتبار از متد GetCredit
     */
    public function credit(): int
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/GetCredit')
            ->throw();

        $this->parseRestResponse($response->json());

        return (int) ($this->apiData['Result'] ?? 0);
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
     * ارسال OTP از طریق SendMessageWithCode.ashx
     */
    protected function sendOtp(string $phone, string $code, string $from): static
    {
        $data = [
            'Username' => $this->username,
            'Password' => $this->password,
            'Mobile' => $phone,
            'Message' => $code,
        ];

        $response = Http::get($this->otpUrl, $data)
            ->throw();

        $this->parseOtpResponse($response->body());

        return $this;
    }

    /**
     * ارسال کد فعال سازی با کد دلخواه
     * متد SendCode
     * 
     * @param string $phone شماره گیرنده
     * @param string $message متن پیام (شامل کد)
     * @return array{success: bool, result_code: int, message: string}
     */
    public function sendCustomCode(string $phone, string $message): array
    {
        $data = [
            'Mobile' => $phone,
            'Message' => $message,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/SendCode', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'result_code' => $this->apiStatusCode,
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * بررسی صحت کد فعال سازی
     * متد CheckSendCode
     * 
     * @param string $phone شماره گیرنده
     * @param string $code کد فعال سازی
     * @return array{success: bool, is_valid: bool, message: string}
     */
    public function verifyCode(string $phone, string $code): array
    {
        $data = [
            'Mobile' => $phone,
            'Code' => $code,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/CheckSendCode', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'is_valid' => $this->apiSuccess && ($this->apiData['Result'] ?? false) === true,
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * {@inheritdoc}
     * ارسال با الگو (از طریق متد SendMessage با PatternId)
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = array_merge([
            'AccessHash' => $this->token,
            'PhoneNumber' => $from,
            'PatternId' => $patternCode,
            'Mobiles' => $phones,
            'UserGroupID' => (string) Str::ulid(),
            'SendDateInTimeStamp' => now()->timestamp,
        ], $variables);

        $this->execute('smsApiWithPattern/SendMessage', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال پیام گروهی از طریق متد SendMessage
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'PhoneNumber' => $from,
            'Message' => $message,
            'Mobiles' => $phones,
            'UserGroupID' => (string) Str::ulid(),
            'SendDateInTimeStamp' => now()->timestamp,
        ];

        $this->execute('smsAPI/SendMessage', $data);

        return $this;
    }

    /**
     * ارسال پیام متناظر (نظیر به نظیر)
     * متد SendCorrespondingMessage
     * 
     * @param string $from شماره فرستنده
     * @param array<array{id: string, message: string, mobile: string}> $recipients
     * @param string|null $userGroupId شناسه گروه (اختیاری)
     * @return array{success: bool, results: array, message: string}
     */
    public function sendCorresponding(string $from, array $recipients, ?string $userGroupId = null): array
    {
        $data = [
            'PhoneNumber' => $from,
            'RecipientsMessage' => $recipients,
            'UserGroupID' => $userGroupId ?? (string) Str::ulid(),
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/SendCorrespondingMessage', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'results' => $this->apiData['Result'] ?? [],
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * دریافت وضعیت ارسال گروهی
     * متد GroupMessageStatus
     * 
     * @param string $groupMessageId شناسه گروه پیام
     * @return array{success: bool, status_code: string|null, messages_status: array, message: string}
     */
    public function getGroupMessageStatus(string $groupMessageId): array
    {
        $data = [
            'GroupMessageId' => $groupMessageId,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/GroupMessageStatus', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        $result = $this->apiData['Result'] ?? [];

        return [
            'success' => $this->apiSuccess,
            'status_code' => $result['StatusCode'] ?? null,
            'messages_status' => $result['MessagesListState'] ?? [],
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * دریافت وضعیت ارسال متناظر
     * متد CorrespondingMessageStatus
     * 
     * @param array<string> $messageIds شناسه‌های پیام
     * @return array{success: bool, statuses: array, message: string}
     */
    public function getCorrespondingStatus(array $messageIds): array
    {
        $data = [
            'messageId' => $messageIds,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/CorrespondingMessageStatus', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'statuses' => $this->apiData['Result'] ?? [],
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * دریافت شناسه گروه پیام
     * متد GetGroupMessageId
     * 
     * @param string $groupId شناسه گروه کاربر
     * @return array{success: bool, message_id: string|null, message: string}
     */
    public function getGroupMessageId(string $groupId): array
    {
        $data = [
            'groupId' => $groupId,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/GetGroupMessageId', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'message_id' => $this->apiData['Result'] ?? null,
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی
     * متد ReceiveMessages
     * 
     * @param string $phoneNumber شماره خط اختصاصی
     * @param int $startDate تاریخ شروع (Timestamp)
     * @param int $endDate تاریخ پایان (Timestamp)
     * @param int $page شماره صفحه
     * @return array{success: bool, messages: array, page: int, total_page: int, message: string}
     */
    public function receiveMessages(string $phoneNumber, int $startDate, int $endDate, int $page = 1): array
    {
        $data = [
            'PhoneNumber' => $phoneNumber,
            'StartDate' => $startDate,
            'EndDate' => $endDate,
            'Page' => $page,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/ReceiveMessages', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        $result = $this->apiData['Result'] ?? [];

        return [
            'success' => $this->apiSuccess,
            'messages' => $result['ReceivedMsgs'] ?? [],
            'page' => $result['Page'] ?? 1,
            'total_page' => $result['TotalPage'] ?? 1,
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * دریافت قیمت پیامک
     * متد GetPrices
     * 
     * @return array{success: bool, fa_price: int, en_price: int, message: string}
     */
    public function getPrices(): array
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/GetPrices')
            ->throw();

        $this->parseRestResponse($response->json());

        $result = $this->apiData['Result'] ?? [];

        return [
            'success' => $this->apiSuccess,
            'fa_price' => (int) ($result['Fa_Price'] ?? 0),
            'en_price' => (int) ($result['En_Price'] ?? 0),
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * بررسی شماره‌ها در لیست سفید (غیر سیاه)
     * متد ShowWhiteList
     * 
     * @param array<string> $mobiles لیست شماره موبایل‌ها
     * @return array{success: bool, white_list: array, message: string}
     */
    public function showWhiteList(array $mobiles): array
    {
        $data = [
            'Mobile' => $mobiles,
        ];

        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post('smsAPI/ShowWhiteList', $data)
            ->throw();

        $this->parseRestResponse($response->json());

        return [
            'success' => $this->apiSuccess,
            'white_list' => $this->apiData['Result'] ?? [],
            'message' => $this->apiErrorMessage,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiSuccess;
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
        $response = Http::withBasicAuth($this->username, $this->password)
            ->baseUrl($this->restBaseUrl)
            ->post($endpoint, $data)
            ->throw();

        $this->parseRestResponse($response->json());
    }

    /**
     * Extracts OTP API response data into status properties.
     */
    private function parseOtpResponse(string|int $response): void
    {
        $this->apiStatusCode = (int) $response;
        $this->apiErrorMessage = sprintf('خطا با کد "%s" رخ داده است.', $this->apiStatusCode);
        $this->apiSuccess = $this->apiStatusCode > 2000;

        if ($this->apiSuccess) {
            $this->setMessageId((string) $this->apiStatusCode);
            $this->setSuccessCount(1);
        }
    }

    /**
     * Extracts REST API response data into status properties.
     *
     * @param  array<string,mixed>  $response
     */
    private function parseRestResponse(array $response): void
    {
        $this->apiStatusCode = (int) ($response['Code'] ?? 0);
        $this->apiErrorMessage = $response['Message'] ?? '';
        $this->apiData = $response;

        $this->apiSuccess = $this->apiStatusCode === 0;

        if ($this->apiSuccess && isset($this->apiData['Result'])) {
            if (is_string($this->apiData['Result']) && is_numeric($this->apiData['Result'])) {
                $this->setMessageId($this->apiData['Result']);
                $this->setSuccessCount(1);
            }
        }
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