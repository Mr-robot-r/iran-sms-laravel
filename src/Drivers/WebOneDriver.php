<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\UnsupportedMethodException;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * @see https://webone-sms.ir/Home/WebServices
 */
final class WebOneDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://api.payamakapi.ir/api/v1';

    /**
     * The sent status returned in the API response body (e.g., `succeeded` field).
     */
    private bool $apiStatus;

    /**
     * The status code returned in the API response body (e.g., `resultCode` field).
     */
    private int $apiStatusCode;

    /**
     * The reference ID returned from API
     */
    private ?string $refId;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {
    }

    /**
     * {@inheritdoc}
     * دریافت مانده اعتبار از متد GetCredit
     */
    public function credit(): int
    {
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->get('SMS/GetCredit')
            ->throw();

        return (int) $response->body();
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
     * ارسال OTP از طریق متد SmartOTP
     */
    protected function sendOtp(string $phone, string $message, string $from): static
    {
        $data = [
            'OTPSender' => $from,
            'ToNumber' => $phone,
            'Content' => $message,
        ];

        $this->execute('SMS/SmartOTP', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * وب وان اسمس از الگو پشتیبانی نمی‌کند (فقط SmartOTP دارد)
     *
     * @throws UnsupportedMethodException
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'pattern', alternative: 'text');
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک به یک یا چند شماره از طریق متد Send
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'From' => $from,
            'Content' => $message,
        ];

        // اگر یک شماره باشد
        if (count($phones) === 1) {
            $data['ToNumber'] = $phones[0];
        } else {
            // اگر چند شماره باشد
            $data['ToNumbers'] = $phones;
        }

        $this->execute('SMS/Send', $data);

        return $this;
    }

    /**
     * ارسال پیامک متناظر (نظیر به نظیر)
     * هر گیرنده متن جداگانه
     * 
     * @param string $from شماره فرستنده
     * @param array<array{to: string, content: string}> $messages
     * @return array{success: bool, ref_id: string|null, message: string}
     */
    public function sendMultiple(string $from, array $messages): array
    {
        $toNumbers = [];
        $contents = [];

        foreach ($messages as $msg) {
            $toNumbers[] = $msg['to'];
            $contents[] = $msg['content'];
        }

        $data = [
            'From' => $from,
            'ToNumbers' => $toNumbers,
            'Contents' => $contents,
        ];

        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->post('SMS/Send', $data)
            ->throw()
            ->json();

        $this->apiStatus = (bool) ($response['succeeded'] ?? false);
        $this->apiStatusCode = (int) ($response['resultCode'] ?? 0);
        $this->refId = $response['refId'] ?? null;

        if ($this->apiStatus) {
            $this->setMessageId($this->refId);
            $this->setSuccessCount(count($messages));
        }

        return [
            'success' => $this->apiStatus,
            'ref_id' => $this->refId,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت گزارش تحویل پیامک
     * متد Delivery
     * 
     * @param string $refId شناسه مرجع پیام
     * @return array{success: bool, deliveries: array, message: string}
     */
    public function getDelivery(string $refId): array
    {
        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->post('SMS/Delivery', ['refId' => $refId])
            ->throw()
            ->json();

        $this->apiStatus = (bool) ($response['Status'] ?? false);
        $this->apiStatusCode = $this->apiStatus ? 0 : 1;

        if ($this->apiStatus) {
            $deliveries = $response['Deliveries'] ?? [];
            return [
                'success' => true,
                'deliveries' => $deliveries,
                'message' => 'گزارش تحویل دریافت شد',
            ];
        }

        return [
            'success' => false,
            'deliveries' => [],
            'message' => $response['Message'] ?? 'خطا در دریافت گزارش',
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
        return match ($this->apiStatusCode) {
            0 => 'ارسال با موفقيت انجام شد',
            1 => 'نام كاربر يا كلمه عبور نامعتبر مي باشد',
            2 => 'كاربر مسدود شده است',
            3 => 'شماره فرستنده نامعتبر است',
            4 => 'محدوديت در ارسال روزانه',
            5 => 'تعداد گيرندگان حداكثر 100 شماره مي باشد',
            6 => 'خط فرستنده غيرفعال است',
            7 => 'متن پيامك شامل كلمات فيلتر شده است',
            8 => 'اعتبار كافي نيست',
            9 => 'سامانه در حال بروز رساني است',
            10 => 'وب سرويس غيرفعال است',
            12 => 'تعداد پيامها و شماره ها بايد يكسان باشد',
            13 => 'حداكثر تعداد مجاز در يك درخواست ارسال متناظر 500 شماره مي باشد',
            14 => 'كاربر فاقد تعرفه مي باشد',
            15 => 'ارسال تكراري متن مشابه به شماره مشابه در مدت زمان مشخص',
            16 => 'موبايل گيرنده يافت نشد',
            17 => 'خط OTP براي كاربر يافت نشد',
            18 => 'با اين شماره فقط ارسال تكي مجاز است',
            19 => 'متن ارسالي شما با الگوي تعريفي شما مطابقت ندارد',
            21 => 'آي پي شما براي ارسال از وب سرويس مجاز نمي باشد',
            22 => 'عدم تاييد يا ارسال كارت ملي كاربر',
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

    // ==================== متدهای مدیریت کاربران ====================

    /**
     * ایجاد کاربر جدید (زیرمجموعه)
     * متد CreateUser
     * 
     * @param string $firstName نام
     * @param string $lastName نام خانوادگی
     * @param string $mobile شماره موبایل
     * @param string $nationalCode کد ملی
     * @return array{success: bool, username: string|null, password: string|null, message: string}
     */
    public function createUser(string $firstName, string $lastName, string $mobile, string $nationalCode): array
    {
        $data = [
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'MobileAlias' => $mobile,
            'NationalCode' => $nationalCode,
        ];

        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->post('User/CreateUser', $data)
            ->throw()
            ->json();

        $success = (bool) ($response['success'] ?? false);
        $message = $response['message'] ?? '';

        if ($success) {
            return [
                'success' => true,
                'username' => $response['userName'] ?? null,
                'password' => $response['password'] ?? null,
                'message' => $message ?: 'کاربر با موفقیت ایجاد شد',
            ];
        }

        return [
            'success' => false,
            'username' => null,
            'password' => null,
            'message' => $message ?: 'خطا در ایجاد کاربر',
        ];
    }

    /**
     * شارژ دستی کاربر (برای نمایندگان)
     * متد ApplyManualCharge
     * 
     * @param int $userId شناسه کاربر
     * @param float $chargeAmount مبلغ شارژ به ریال
     * @return array{success: bool, message: string}
     */
    public function manualCharge(int $userId, float $chargeAmount): array
    {
        $response = Http::withHeaders($this->credentials())
            ->asForm()
            ->baseUrl($this->baseUrl)
            ->post('User/ApplyManualCharge', [
                'userId' => $userId,
                'chargeAmount' => $chargeAmount,
            ])
            ->throw()
            ->json();

        $success = (bool) ($response['success'] ?? false);

        return [
            'success' => $success,
            'message' => $response['message'] ?? ($success ? 'شارژ با موفقیت انجام شد' : 'خطا در شارژ کاربر'),
        ];
    }

    // ==================== متدهای مدیریت دفترچه تلفن ====================

    /**
     * اضافه کردن شماره به دفترچه تلفن
     * متد ApplyContact
     * 
     * @param string $mobile شماره موبایل (اجباری)
     * @param string|null $firstName نام (اختیاری)
     * @param string|null $lastName نام خانوادگی (اختیاری)
     * @return array{success: bool, message: string}
     */
    public function addContact(string $mobile, ?string $firstName = null, ?string $lastName = null): array
    {
        $data = [
            'Mobile' => $mobile,
        ];

        if ($firstName) {
            $data['FirstName'] = $firstName;
        }
        if ($lastName) {
            $data['LastName'] = $lastName;
        }

        $response = Http::withHeaders($this->credentials())
            ->baseUrl($this->baseUrl)
            ->post('User/ApplyContact', $data)
            ->throw()
            ->json();

        $success = (bool) ($response['success'] ?? false);

        return [
            'success' => $success,
            'message' => $response['message'] ?? ($success ? 'شماره با موفقیت به دفترچه تلفن اضافه شد' : 'خطا در اضافه کردن شماره'),
        ];
    }

    /**
     * توجه: وب وان اسمس متدهای زیر را پشتیبانی نمی‌کند:
     * - دریافت لیست گروه‌ها
     * - دریافت لیست مخاطبین
     * - حذف مخاطب
     * - ویرایش مخاطب
     * - ایجاد گروه
     * 
     * تنها متد ApplyContact برای اضافه کردن شماره به دفترچه تلفن وجود دارد.
     */

    // ==================== متدهای غیرقابل پشتیبانی ====================

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
            ->throw()
            ->json();

        $this->apiStatus = (bool) ($response['succeeded'] ?? false);
        $this->apiStatusCode = (int) ($response['resultCode'] ?? 0);
        $this->refId = $response['refId'] ?? null;

        if ($this->apiStatus && $this->refId) {
            $this->setMessageId($this->refId);

            // تعداد ارسال‌های موفق
            if (isset($data['ToNumbers']) && is_array($data['ToNumbers'])) {
                $this->setSuccessCount(count($data['ToNumbers']));
            } else {
                $this->setSuccessCount(1);
            }
        }
    }

    /**
     * @return array{X-API-KEY: string}
     */
    private function credentials(): array
    {
        return [
            'X-API-KEY' => $this->token,
        ];
    }
}