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
 * @see https://sms.ir/rest-api/
 */
final class SmsIrDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://api.sms.ir/v1/';

    /**
     * The status code returned in the API response body (e.g., `status` field).
     */
    private int $apiStatusCode;

    /**
     * The message returned from API
     */
    private string $apiMessage;

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
     * دریافت اعتبار از متد credit
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->acceptJson()
            ->get('credit')
            ->throw();

        $this->processResponse($response);

        return (int) $response->json('data');
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
     * ارسال با الگو از طریق متد send/verify
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = [
            'mobile' => $phones[0],
            'templateId' => (int) $patternCode,
            'parameters' => $this->toApiPattern($variables),
        ];

        $this->execute('send/verify', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال گروهی از طریق متد send/bulk
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'lineNumber' => $from,
            'messageText' => $message,
            'mobiles' => $phones,
            'sendDateTime' => null,
        ];

        $this->execute('send/bulk', $data);

        return $this;
    }

    /**
     * دریافت وضعیت پیامک با شناسه
     * متد send/receive
     * 
     * @param int $messageId شناسه پیامک
     * @return array{success: bool, status: string|null, message: string}
     */
    public function getMessageStatus(int $messageId): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->acceptJson()
            ->get('send/receive/' . $messageId)
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
     * دریافت لیست پیامک‌های دریافتی
     * 
     * @param int $page شماره صفحه
     * @param int $pageSize تعداد در هر صفحه
     * @return array{success: bool, messages: array, message: string}
     */
    public function getReceivedMessages(int $page = 1, int $pageSize = 50): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->acceptJson()
            ->get('receive', [
                'page' => $page,
                'pageSize' => $pageSize,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $messages = $this->apiData['messages'] ?? [];
            return [
                'success' => true,
                'messages' => $messages,
                'message' => 'لیست پیامک‌های دریافتی دریافت شد',
            ];
        }

        return [
            'success' => false,
            'messages' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست خطوط ارسال کننده
     * 
     * @return array{success: bool, lines: array, message: string}
     */
    public function getLines(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->acceptJson()
            ->get('line')
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $lines = $this->apiData['lines'] ?? [];
            return [
                'success' => true,
                'lines' => $lines,
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
     * 
     * @return array{success: bool, templates: array, message: string}
     */
    public function getTemplates(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->acceptJson()
            ->get('template')
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $templates = $this->apiData['templates'] ?? [];
            return [
                'success' => true,
                'templates' => $templates,
                'message' => 'لیست الگوها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'templates' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 1;
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
            1 => 'عملیات با موفقیت انجام شد',
            0 => 'مشکلی در سامانه رخ داده است، لطفا با پشتیبانی در تماس باشید',
            10 => 'کلید وب سرویس نامعتبر است',
            11 => 'کلید وب سرویس غیرفعال است',
            12 => 'کلید وب سرویس محدود به IP های تعریف شده می‌باشد',
            13 => 'حساب کاربری غیر فعال است',
            14 => 'حساب کاربری در حالت تعلیق قرار دارد',
            20 => 'تعداد درخواست بیشتر از حد مجاز است',
            101 => 'شماره خط نامعتبر میباشد',
            102 => 'اعتبار کافی نمیباشد',
            103 => 'درخواست شما دارای متن(های) خالی است',
            104 => 'درخواست شما دارای موبایل(های) نادرست است',
            105 => 'تعداد موبایل ها بیشتر از حد مجاز (100 عدد) می‌باشد',
            106 => 'تعداد متن ها بیشتر از حد مجاز (100 عدد) می‌باشد',
            107 => 'لیست موبایل ها خالی میباشد',
            108 => 'لیست متن ها خالی میباشد',
            109 => 'زمان ارسال نامعتبر میباشد',
            110 => 'تعداد شماره موبایل ها و تعداد متن ها برابر نیستند',
            111 => 'با این شناسه ارسالی ثبت نشده است',
            112 => 'رکوردی برای حذف یافت نشد',
            113 => 'قالب یافت نشد',
            114 => 'طول رشته مقدار پارامتر، بیش از حد مجاز (25 کاراکتر) می‌باشد',
            115 => 'شماره موبایل ها در لیست سیاه سامانه می‌باشند',
            116 => 'نام پارامتر نمی‌تواند خالی باشد',
            117 => 'متن ارسال شده مورد تایید نمی‌باشد',
            118 => 'تعداد پیام ها بیش از حد مجاز می باشد.',
            119 => 'به منظور استفاده از قالب شخصی سازی شده پلن خود را ارتقا دهید',
            123 => 'خط ارسال‌کننده نیاز به فعال‌سازی دارد',
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
            ->withHeaders($this->credentials())
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
        $this->apiStatusCode = (int) $response->json('status');
        $this->apiMessage = $response->json('message') ?? '';
        $this->apiData = $response->json('data') ?? [];

        if ($this->isSuccessful() && isset($this->apiData['messageId'])) {
            $this->setMessageId((string) $this->apiData['messageId']);
            $this->setSuccessCount(1);
        }

        if ($this->isSuccessful() && isset($this->apiData['bulkId'])) {
            $this->setMessageId((string) $this->apiData['bulkId']);
            $this->setSuccessCount(count($this->apiData['mobiles'] ?? []));
        }
    }

    /**
     * @return array{x-api-key: string}
     */
    private function credentials(): array
    {
        return [
            'x-api-key' => $this->token,
        ];
    }

    /**
     * Transforms variables into the API's expected pattern structure.
     *
     * @param  array<string, mixed>  $variables
     * @return list<array{name: string, value: mixed}>
     *
     * @example - ['key_one' => 'value_one'] becomes [['name' => 'key_one', 'value' => 'value_one']]
     */
    private function toApiPattern(array $variables): array
    {
        return collect($variables)
            ->map(fn(mixed $value, string $key): array => [
                'name' => $key,
                'value' => $value,
            ])
            ->values()
            ->all();
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