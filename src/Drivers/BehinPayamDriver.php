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
 * @see https://doc.sms-webservice.com/
 */
final class BehinPayamDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://api.sms-webservice.com/api/V3';

    /**
     * The response data from API
     */
    private array $apiData;

    /**
     * The status of API response
     */
    private bool $apiSuccess;

    /**
     * The error message from API
     */
    private string $apiMessage;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {
    }

    /**
     * {@inheritdoc}
     * متد AccountInfo
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->post('AccountInfo', $this->credentials())
            ->throw();

        $this->processResponse($response);

        return (int) $response->json('Credit');
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

        $data = array_merge([
            'Destination' => $phones[0],
            'TemplateKey' => $patternCode,
        ], $variables);

        $this->execute('SendTokenSingle', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * متد Send
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'Sender' => $from,
            'Text' => $message,
            'Recipients' => $phones,
        ];

        $this->execute('Send', $data);

        return $this;
    }

    /**
     * ارسال پیامک نظیر به نظیر (هر گیرنده متن جداگانه)
     * متد SendMultiple
     * 
     * @param array<array{recipient: string, text: string}> $items
     */
    public function sendMultiple(array $items, string $from): static
    {
        $data = [
            'Sender' => $from,
            'Messages' => $items,
        ];

        $this->execute('SendMultiple', $data);

        return $this;
    }

    /**
     * ارسال تماس صوتی
     * متد SendVoice
     * 
     * @param string $phone شماره گیرنده
     * @param string $message متن پیام صوتی
     */
    public function sendVoice(string $phone, string $message): static
    {
        $data = [
            'Recipient' => $phone,
            'Text' => $message,
        ];

        $this->execute('SendVoice', $data);

        return $this;
    }

    /**
     * دریافت وضعیت تحویل پیامک
     * متد GetDelivery
     * 
     * @param int $messageId شناسه پیامک
     * @return array{success: bool, status: string|null, message: string}
     */
    public function getDeliveryStatus(int $messageId): array
    {
        $data = [
            'Id' => $messageId,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->get('GetDelivery', array_merge($this->credentials(), $data))
            ->throw();

        $this->processResponse($response);

        if ($this->apiSuccess) {
            return [
                'success' => true,
                'status' => $this->apiData['Status'] ?? null,
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
     * متد GetInbox
     * 
     * @param int $count تعداد پیامک (حداکثر 100)
     * @return array{success: bool, messages: array, message: string}
     */
    public function getInbox(int $count = 20): array
    {
        $data = [
            'Count' => min($count, 100),
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->get('GetInbox', array_merge($this->credentials(), $data))
            ->throw();

        $this->processResponse($response);

        if ($this->apiSuccess) {
            $messages = $this->apiData['Messages'] ?? [];
            $formattedMessages = array_map(function ($msg) {
                return [
                    'id' => $msg['Id'] ?? null,
                    'message' => $msg['Message'] ?? '',
                    'sender' => $msg['Sender'] ?? '',
                    'receiver' => $msg['Receiver'] ?? '',
                    'date' => $msg['Date'] ?? '',
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
     * دریافت لیست سیاه
     * متد GetBlackList
     * 
     * @return array{success: bool, blacklist: array, message: string}
     */
    public function getBlacklist(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->get('GetBlackList', $this->credentials())
            ->throw();

        $this->processResponse($response);

        if ($this->apiSuccess) {
            return [
                'success' => true,
                'blacklist' => $this->apiData['BlackList'] ?? [],
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
     * افزودن شماره به لیست سیاه
     * متد AddToBlackList
     * 
     * @param string $mobile شماره موبایل
     * @return array{success: bool, message: string}
     */
    public function addToBlacklist(string $mobile): array
    {
        $data = [
            'Mobile' => $mobile,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('AddToBlackList', array_merge($this->credentials(), $data))
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->apiSuccess,
            'message' => $this->apiSuccess ? 'شماره با موفقیت به لیست سیاه اضافه شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * حذف شماره از لیست سیاه
     * متد RemoveFromBlackList
     * 
     * @param string $mobile شماره موبایل
     * @return array{success: bool, message: string}
     */
    public function removeFromBlacklist(string $mobile): array
    {
        $data = [
            'Mobile' => $mobile,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('RemoveFromBlackList', array_merge($this->credentials(), $data))
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->apiSuccess,
            'message' => $this->apiSuccess ? 'شماره با موفقیت از لیست سیاه حذف شد' : $this->getErrorMessage(),
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
        return $this->apiMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->apiSuccess ? 200 : 400;
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
            ->post($endpoint, array_merge($this->credentials(), $data))
            ->throw();

        $this->processResponse($response);
    }

    /**
     * Process API response
     */
    private function processResponse($response): void
    {
        $this->apiData = $response->json();
        $this->apiSuccess = isset($this->apiData['IsSuccessful']) ? $this->apiData['IsSuccessful'] : true;
        $this->apiMessage = $this->apiData['Message'] ?? '';

        if ($this->apiSuccess && isset($this->apiData['Id'])) {
            $this->setMessageId((string) $this->apiData['Id']);
            $this->setSuccessCount(1);
        }

        if ($this->apiSuccess && isset($this->apiData['Ids'])) {
            $this->setSuccessCount(count($this->apiData['Ids']));
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
        if (count($variables) !== 3) {
            throw new InvalidPatternStructureException(
                sprintf('Provider "%s" only accepts pattern data with exactly 3 items.', $this->getDriverName())
            );
        }

        if (Arr::isList($variables)) {
            throw new InvalidPatternStructureException(
                sprintf('Provider "%s" only accepts pattern data as key-value pairs.', $this->getDriverName())
            );
        }
    }
}