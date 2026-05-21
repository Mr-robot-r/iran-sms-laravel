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
 * @see https://asanak.com/api-docs
 */
final class AsanakDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://sms.asanak.ir/webservice/v2rest';

    /**
     * The status code returned in the API response body (e.g., `meta.status` field).
     */
    private int $apiStatusCode;

    /**
     * The message returned in the API response body
     */
    private string $apiMessage;

    /**
     * The data returned from API
     */
    private array $apiData;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    /**
     * {@inheritdoc}
     * متد getrialcredit
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('getrialcredit', $this->credentials())
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
     * @throws UnsupportedMultiplePhonesException
     * @throws InvalidPatternStructureException
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = [
            'template_id' => $patternCode,
            'destination' => $phones[0],
            'parameters' => $variables,
            'send_to_blacklist' => 1,
        ];

        $this->execute('template', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * متد sendsms
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'source' => $from,
            'message' => $message,
            'destination' => $this->toApiPhones($phones),
            'send_to_blacklist' => 1,
        ];

        $this->execute('sendsms', $data);

        return $this;
    }

    /**
     * دریافت وضعیت تحویل پیامک
     * متد checkdeliverystatus
     * 
     * @param string|array $messageIds شناسه پیامک (یک یا چندتا)
     * @return array{success: bool, statuses: array, message: string}
     */
    public function checkDeliveryStatus($messageIds): array
    {
        $ids = is_array($messageIds) ? implode(',', $messageIds) : $messageIds;

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('checkdeliverystatus', array_merge($this->credentials(), [
                'message_id' => $ids,
            ]))
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'statuses' => $this->apiData['statuses'] ?? [],
                'message' => 'وضعیت پیامک دریافت شد',
            ];
        }

        return [
            'success' => false,
            'statuses' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی
     * متد getmessages
     * 
     * @param int $count تعداد پیامک (حداکثر 50)
     * @return array{success: bool, messages: array, message: string}
     */
    public function getReceivedMessages(int $count = 10): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('getmessages', array_merge($this->credentials(), [
                'count' => min($count, 50),
            ]))
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $messages = $this->apiData['messages'] ?? [];
            $formattedMessages = array_map(function ($msg) {
                return [
                    'id' => $msg['id'] ?? null,
                    'message' => $msg['message'] ?? '',
                    'sender' => $msg['sender'] ?? '',
                    'receiver' => $msg['receiver'] ?? '',
                    'date' => $msg['date'] ?? '',
                ];
            }, $messages);

            return [
                'success' => true,
                'messages' => $formattedMessages,
                'message' => 'پیامک‌های دریافتی دریافت شد',
            ];
        }

        return [
            'success' => false,
            'messages' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * افزودن شماره به لیست سیاه
     * متد blacklist (add)
     * 
     * @param string $mobile شماره موبایل
     * @return array{success: bool, message: string}
     */
    public function addToBlacklist(string $mobile): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('blacklist', array_merge($this->credentials(), [
                'action' => 'add',
                'mobile' => $mobile,
            ]))
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'شماره با موفقیت به لیست سیاه اضافه شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * حذف شماره از لیست سیاه
     * متد blacklist (remove)
     * 
     * @param string $mobile شماره موبایل
     * @return array{success: bool, message: string}
     */
    public function removeFromBlacklist(string $mobile): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('blacklist', array_merge($this->credentials(), [
                'action' => 'remove',
                'mobile' => $mobile,
            ]))
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'شماره با موفقیت از لیست سیاه حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست سیاه
     * متد blacklist (list)
     * 
     * @return array{success: bool, blacklist: array, message: string}
     */
    public function getBlacklist(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('blacklist', array_merge($this->credentials(), [
                'action' => 'list',
            ]))
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'blacklist' => $this->apiData['blacklist'] ?? [],
                'message' => 'لیست سیاه دریافت شد',
            ];
        }

        return [
            'success' => false,
            'blacklist' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت گزارش ارسال‌ها
     * متد report
     * 
     * @param string|null $fromDate تاریخ شروع (اختیاری)
     * @param string|null $toDate تاریخ پایان (اختیاری)
     * @return array{success: bool, report: array, message: string}
     */
    public function getReport(?string $fromDate = null, ?string $toDate = null): array
    {
        $params = [];
        if ($fromDate) {
            $params['from_date'] = $fromDate;
        }
        if ($toDate) {
            $params['to_date'] = $toDate;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('report', array_merge($this->credentials(), $params))
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'report' => $this->apiData,
                'message' => 'گزارش دریافت شد',
            ];
        }

        return [
            'success' => false,
            'report' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 200;
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorMessage(): string
    {
        if ($this->apiMessage) {
            return $this->apiMessage;
        }

        return match ($this->apiStatusCode) {
            1008 => 'خطای اعتبار سنجی پارامتر های ورودی',
            1014 => 'شماره فرستنده (مبدا) مجاز به ارسال لینک نمی باشد.',
            1015 => 'خطای مربوط به منقضی شدن کلمه عبور وب سرویس',
            1006 => 'خطای مربوط به نداشتن اعتبار کافی برای ارسال',
            1005 => 'خطای مربوطه به نداشتن اعتبار کافی پنل نمایندگی',
            1013 => 'در بازه زمانی غیر مجاز (تبلیغاتی) فقط شماره های خدماتی مجاز به ارسال می باشند.',
            1002 => 'شماره فرستنده (مبدا) فعال نمی باشد.',
            1010 => 'لیست شماره های مقصد (گیرنده) صحیح و معتبر نمی باشد.',
            1009 => 'خطای مربوطه به محدودیت ارسال روزانه وب سرویس می باشد.',
            429 => 'محدودیت درخواست‌ها رسیده است. تعداد درخواست‌ها بیش از حد مجاز است.',
            1004 => 'خطای داخلی در سرور رخ داده است.',
            default => "خطای ناشناخته با کد {$this->apiStatusCode} رخ داده است"
        };
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
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post($endpoint, $data)
            ->throw();

        $this->processResponse($response);
    }

    /**
     * Process API response
     */
    private function processResponse($response): void
    {
        $this->apiStatusCode = (int) $response->json('meta.status');
        $this->apiMessage = $response->json('meta.message') ?? '';
        $this->apiData = $response->json('data') ?? [];

        if ($this->isSuccessful() && isset($this->apiData['message_id'])) {
            $this->setMessageId((string) $this->apiData['message_id']);
            $this->setSuccessCount(1);
        }
    }

    /**
     * @return array{username: string, password: string}
     */
    private function credentials(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    /**
     * Transforms phones into the API's expected phone structure.
     *
     * @param  list<string>  $phones
     *
     * @example - ['0913', '0914'] becomes "0913,0914"
     */
    private function toApiPhones(array $phones): string
    {
        return implode(',', $phones);
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