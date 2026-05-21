<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\UnsupportedMethodException;
use Mastertek\IranSms\Exceptions\UnsupportedMultiplePhonesException;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * @see https://portal.amootsms.com/rest
 */
final class AmootSmsDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://portal.amootsms.com/rest';

    /**
     * Username for API authentication
     */
    private string $username;

    /**
     * Password for API authentication
     */
    private string $password;

    /**
     * The status returned in the API response body
     */
    private bool $apiSuccess;

    /**
     * The message returned in the API response body
     */
    private string $apiMessage;

    /**
     * The data returned from API
     */
    private array $apiData;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
        ?string $username = null,
        ?string $password = null,
    ) {
        $this->username = $username ?? config('sms.providers.amoot.username', '');
        $this->password = $password ?? config('sms.providers.amoot.password', '');
    }

    /**
     * Get base credentials array for all requests
     */
    private function getBaseCredentials(): array
    {
        return [
            'Token' => $this->token,
            'UserName' => $this->username,
            'Password' => $this->password,
        ];
    }

    /**
     * {@inheritdoc}
     * متد AccountStatus
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->get('AccountStatus', $this->getBaseCredentials())
            ->throw();

        $this->processResponse($response);

        // پاسخ به صورت رشته‌ای برمی‌گردد (مثلاً "100000")
        $content = $response->body();
        return (int) $content;
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
     * ارسال OTP با متد SendQuickOTP
     */
    protected function sendOtp(string $phone, string $code, string $from): static
    {
        $data = array_merge($this->getBaseCredentials(), [
            'Mobile' => $phone,
            'CodeLength' => strlen($code),
            'OptionalCode' => $code,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('SendQuickOTP', $data)
            ->throw();

        $this->processResponse($response);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک با الگو (متد SendWithPattern)
     * 
     * @param array<string> $phones
     * @param string $patternCode شناسه الگو (int)
     * @param array<string, mixed> $variables مقادیر الگو (با کاما جدا می‌شوند)
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);

        $data = array_merge($this->getBaseCredentials(), [
            'Mobile' => $phones[0],
            'PatternCodeID' => (int) $patternCode,
            'PatternValues' => $this->toApiPattern($variables),
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('SendWithPattern', $data)
            ->throw();

        $this->processResponse($response);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک ساده (متد SendSimple)
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = array_merge($this->getBaseCredentials(), [
            'SendDateTime' => now('Asia/Tehran')->toIso8601String(),
            'SMSMessageText' => $message,
            'LineNumber' => $from,
            'Mobiles' => $this->toApiPhones($phones),
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('SendSimple', $data)
            ->throw();

        $this->processResponse($response);

        return $this;
    }

    /**
     * ارسال نظیر به نظیر (متد SendPeerToPeer)
     * 
     * @param array<array{mobile: string, message: string}> $items
     */
    public function sendPeerToPeer(array $items, string $from): static
    {
        $input = [];
        foreach ($items as $item) {
            $input[] = [
                'Mobile' => $item['mobile'],
                'SMSMessageText' => $item['message'],
            ];
        }

        $data = array_merge($this->getBaseCredentials(), [
            'SendDateTime' => now('Asia/Tehran')->toIso8601String(),
            'LineNumber' => $from,
            'Input' => $input,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('SendPeerToPeer', $data)
            ->throw();

        $this->processResponse($response);

        return $this;
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

    // ==================== مدیریت گروه (ContactGroup) ====================

    /**
     * دریافت لیست گروه‌های مخاطبین
     * متد ContactGroupList
     * 
     * @return array{success: bool, groups: array, message: string}
     */
    public function getGroups(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactGroupList', $this->getBaseCredentials())
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $groupsData = $this->apiData;
            $groups = array_map(function ($item) {
                return [
                    'GroupID' => $item['Id'] ?? $item['ContactGroupID'] ?? null,
                    'Name' => $item['Name'] ?? $item['Title'] ?? '',
                    'Description' => $item['Description'] ?? '',
                    'MemberCount' => $item['MemberCount'] ?? 0,
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

    // ==================== مدیریت مخاطب (Contact) ====================

    /**
     * دریافت اطلاعات یک مخاطب
     * متد ContactGet
     * 
     * @param int $contactId شناسه مخاطب
     * @return array{success: bool, contact: array|null, message: string}
     */
    public function getContact(int $contactId): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'ContactID' => $contactId,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactGet', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact' => $this->formatContact($this->apiData),
                'message' => 'اطلاعات مخاطب دریافت شد',
            ];
        }

        return [
            'success' => false,
            'contact' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست مخاطبین یک گروه
     * متد ContactList
     * 
     * @param int|null $groupId شناسه گروه (اختیاری)
     * @param int $page شماره صفحه
     * @param int $perPage تعداد در هر صفحه
     * @return array{success: bool, contacts: array, total: int, message: string}
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'ContactGroupID' => (int) $groupId,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactList', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $contactsData = $this->apiData;
            $contacts = array_map([$this, 'formatContact'], $contactsData);

            return [
                'success' => true,
                'contacts' => $contacts,
                'total' => count($contacts),
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
     * جستجوی مخاطبین
     * متد ContactSearch
     * 
     * @param array{
     *     mobile?: string,
     *     fname?: string,
     *     lname?: string,
     *     email?: string,
     *     company_title?: string,
     *     city_name?: string,
     *     labels?: string
     * } $searchParams
     * @return array{success: bool, contacts: array, message: string}
     */
    public function searchContacts(array $searchParams): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'Mobile' => $searchParams['mobile'] ?? '',
            'Labels' => $searchParams['labels'] ?? '',
            'FName' => $searchParams['fname'] ?? '',
            'LName' => $searchParams['lname'] ?? '',
            'CompanyTitle' => $searchParams['company_title'] ?? '',
            'JobTitle' => $searchParams['job_title'] ?? '',
            'Email' => $searchParams['email'] ?? '',
            'CityName' => $searchParams['city_name'] ?? '',
            'AddressText' => $searchParams['address'] ?? '',
            'BornDate' => $searchParams['born_date'] ?? '',
            'AnniversaryDate' => $searchParams['anniversary_date'] ?? '',
            'CustomText1' => $searchParams['custom_text1'] ?? '',
            'CustomText2' => $searchParams['custom_text2'] ?? '',
            'CustomText3' => $searchParams['custom_text3'] ?? '',
            'CustomText4' => $searchParams['custom_text4'] ?? '',
            'CustomText5' => $searchParams['custom_text5'] ?? '',
            'CustomText6' => $searchParams['custom_text6'] ?? '',
            'CustomDate1' => $searchParams['custom_date1'] ?? '',
            'CustomDate2' => $searchParams['custom_date2'] ?? '',
            'CustomDate3' => $searchParams['custom_date3'] ?? '',
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactSearch', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $contactsData = $this->apiData;
            $contacts = array_map([$this, 'formatContact'], $contactsData);

            return [
                'success' => true,
                'contacts' => $contacts,
                'message' => 'نتیجه جستجو دریافت شد',
            ];
        }

        return [
            'success' => false,
            'contacts' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * ایجاد مخاطب جدید
     * متد ContactCreate
     * 
     * @param array{
     *     group_id: int,
     *     mobile: string,
     *     active?: bool,
     *     fname?: string,
     *     lname?: string,
     *     gender_type?: bool,
     *     company_title?: string,
     *     job_title?: string,
     *     email?: string,
     *     city_name?: string,
     *     address?: string,
     *     born_date?: string,
     *     anniversary_date?: string,
     *     labels?: string,
     *     custom_text1?: string,
     *     custom_text2?: string,
     *     custom_text3?: string,
     *     custom_text4?: string,
     *     custom_text5?: string,
     *     custom_text6?: string,
     *     custom_date1?: string,
     *     custom_date2?: string,
     *     custom_date3?: string
     * } $contact
     * @return array{success: bool, contact_id: int|null, message: string}
     */
    public function addContact(array $contact): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'ContactGroupID' => (int) $contact['group_id'],
            'Active' => $contact['active'] ?? true,
            'Mobile' => $contact['mobile'],
            'FName' => $contact['fname'] ?? '',
            'LName' => $contact['lname'] ?? '',
            'GenderType' => $contact['gender_type'] ?? false,
            'CompanyTitle' => $contact['company_title'] ?? '',
            'JobTitle' => $contact['job_title'] ?? '',
            'Email' => $contact['email'] ?? '',
            'CityName' => $contact['city_name'] ?? '',
            'AddressText' => $contact['address'] ?? '',
            'BornDate' => $contact['born_date'] ?? '',
            'AnniversaryDate' => $contact['anniversary_date'] ?? '',
            'CustomText1' => $contact['custom_text1'] ?? '',
            'CustomText2' => $contact['custom_text2'] ?? '',
            'CustomText3' => $contact['custom_text3'] ?? '',
            'CustomText4' => $contact['custom_text4'] ?? '',
            'CustomText5' => $contact['custom_text5'] ?? '',
            'CustomText6' => $contact['custom_text6'] ?? '',
            'CustomDate1' => $contact['custom_date1'] ?? '',
            'CustomDate2' => $contact['custom_date2'] ?? '',
            'CustomDate3' => $contact['custom_date3'] ?? '',
            'Labels' => $contact['labels'] ?? '',
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactCreate', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact_id' => (int) ($this->apiData['ContactID'] ?? $this->apiData['Id'] ?? null),
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
     * ویرایش مخاطب
     * متد ContactEdit
     * 
     * @param int $contactId شناسه مخاطب
     * @param array $data اطلاعات جدید
     * @return array{success: bool, message: string}
     */
    public function editContact(int $contactId, array $data): array
    {
        $requestData = array_merge($this->getBaseCredentials(), [
            'ContactID' => $contactId,
            'ContactGroupID' => (int) ($data['group_id'] ?? 0),
            'Active' => $data['active'] ?? true,
            'Mobile' => $data['mobile'] ?? '',
            'FName' => $data['fname'] ?? '',
            'LName' => $data['lname'] ?? '',
            'GenderType' => $data['gender_type'] ?? false,
            'CompanyTitle' => $data['company_title'] ?? '',
            'JobTitle' => $data['job_title'] ?? '',
            'Email' => $data['email'] ?? '',
            'CityName' => $data['city_name'] ?? '',
            'AddressText' => $data['address'] ?? '',
            'BornDate' => $data['born_date'] ?? '',
            'AnniversaryDate' => $data['anniversary_date'] ?? '',
            'CustomText1' => $data['custom_text1'] ?? '',
            'CustomText2' => $data['custom_text2'] ?? '',
            'CustomText3' => $data['custom_text3'] ?? '',
            'CustomText4' => $data['custom_text4'] ?? '',
            'CustomText5' => $data['custom_text5'] ?? '',
            'CustomText6' => $data['custom_text6'] ?? '',
            'CustomDate1' => $data['custom_date1'] ?? '',
            'CustomDate2' => $data['custom_date2'] ?? '',
            'CustomDate3' => $data['custom_date3'] ?? '',
            'Labels' => $data['labels'] ?? '',
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactEdit', $requestData)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت ویرایش شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * حذف مخاطب
     * متد ContactDelete
     * 
     * @param int $contactId شناسه مخاطب
     * @return array{success: bool, message: string}
     */
    public function deleteContact(string $contactId): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'ContactID' => (int) $contactId,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactDelete', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * تغییر برچسب مخاطب
     * متد ContactChangeLabel
     * 
     * @param string $fromLabel برچسب مبدأ
     * @param string $toLabel برچسب مقصد
     * @return array{success: bool, message: string}
     */
    public function changeContactLabel(string $fromLabel, string $toLabel): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'FromLabel' => $fromLabel,
            'ToLabel' => $toLabel,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('ContactChangeLabel', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'برچسب با موفقیت تغییر کرد' : $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت تعداد مخاطبین گروه (با دریافت لیست و شمارش)
     * 
     * @param string $groupId شناسه گروه
     * @return array{success: bool, count: int, message: string}
     */
    public function getContactsCount(string $groupId): array
    {
        $contacts = $this->getContacts($groupId, 1, 1000);

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
     * دریافت لیست کدهای الگو
     * متد PatternCodeList
     * 
     * @return array{success: bool, patterns: array, message: string}
     */
    public function getPatterns(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->get('PatternCodeList', $this->getBaseCredentials())
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'patterns' => $this->apiData,
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
     * دریافت وضعیت تحویل پیامک
     * متد GetDelivery
     * 
     * @param int $messageId شناسه پیامک
     * @return array{success: bool, status: string|null, delivery_status: int|null, message: string}
     */
    public function getDeliveryStatus(int $messageId): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'MessageID' => $messageId,
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('GetDelivery', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'status' => $this->apiData['Status'] ?? null,
                'delivery_status' => $this->apiData['DeliveryStatus'] ?? null,
                'message' => 'وضعیت پیامک دریافت شد',
            ];
        }

        return [
            'success' => false,
            'status' => null,
            'delivery_status' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی جدید
     * متد RecieveNewMessages
     * 
     * @param int $count تعداد پیامک (حداکثر 100)
     * @return array{success: bool, messages: array, message: string}
     */
    public function receiveNewMessages(int $count = 10): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'Count' => min($count, 100),
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('RecieveNewMessages', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'messages' => $this->apiData,
                'message' => 'پیامک‌های جدید دریافت شد',
            ];
        }

        return [
            'success' => false,
            'messages' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی در بازه زمانی
     * متد RecieveMessages
     * 
     * @param string $fromDateTime تاریخ شروع
     * @param string $toDateTime تاریخ پایان
     * @param string|null $lineNumber شماره خط (اختیاری)
     * @return array{success: bool, messages: array, message: string}
     */
    public function receiveMessages(string $fromDateTime, string $toDateTime, ?string $lineNumber = null): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'FromDateTime' => $fromDateTime,
            'ToDateTime' => $toDateTime,
            'LineNumber' => $lineNumber ?? '',
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('RecieveMessages', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'messages' => $this->apiData,
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
     * محاسبه قیمت پیامک
     * متد CalculateMessagePrice
     * 
     * @param string $message متن پیامک
     * @param string $lineNumber شماره خط
     * @param array<string> $mobiles لیست شماره موبایل‌ها
     * @return array{success: bool, price: int|null, message: string}
     */
    public function calculatePrice(string $message, string $lineNumber, array $mobiles): array
    {
        $data = array_merge($this->getBaseCredentials(), [
            'SMSMessageText' => $message,
            'LineNumber' => $lineNumber,
            'Mobiles' => implode(',', $mobiles),
        ]);

        $response = Http::baseUrl($this->baseUrl)
            ->get('CalculateMessagePrice', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'price' => (int) $this->apiData,
                'message' => 'قیمت پیامک محاسبه شد',
            ];
        }

        return [
            'success' => false,
            'price' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای کمکی ====================

    /**
     * فرمت کردن داده مخاطب به فرمت استاندارد
     */
    private function formatContact(array $contact): array
    {
        return [
            'ContactID' => $contact['Id'] ?? $contact['ContactID'] ?? null,
            'FirstName' => $contact['FName'] ?? $contact['FirstName'] ?? '',
            'LastName' => $contact['LName'] ?? $contact['LastName'] ?? '',
            'MobileNumbers' => $contact['Mobile'] ?? '',
            'Email' => $contact['Email'] ?? '',
            'GroupID' => $contact['ContactGroupID'] ?? $contact['GroupID'] ?? null,
            'CompanyTitle' => $contact['CompanyTitle'] ?? '',
            'JobTitle' => $contact['JobTitle'] ?? '',
            'CityName' => $contact['CityName'] ?? '',
            'Address' => $contact['AddressText'] ?? '',
            'Labels' => $contact['Labels'] ?? '',
            'Active' => $contact['Active'] ?? true,
            'GenderType' => $contact['GenderType'] ?? false,
            'BornDate' => $contact['BornDate'] ?? '',
            'AnniversaryDate' => $contact['AnniversaryDate'] ?? '',
            'CustomText1' => $contact['CustomText1'] ?? '',
            'CustomText2' => $contact['CustomText2'] ?? '',
            'CustomText3' => $contact['CustomText3'] ?? '',
            'CustomText4' => $contact['CustomText4'] ?? '',
            'CustomText5' => $contact['CustomText5'] ?? '',
            'CustomText6' => $contact['CustomText6'] ?? '',
            'CustomDate1' => $contact['CustomDate1'] ?? '',
            'CustomDate2' => $contact['CustomDate2'] ?? '',
            'CustomDate3' => $contact['CustomDate3'] ?? '',
        ];
    }

    /**
     * پردازش پاسخ API
     */
    private function processResponse($response): void
    {
        $content = $response->body();

        // بررسی می‌کنیم پاسخ JSON است یا خیر
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            // پاسخ JSON
            $this->apiSuccess = isset($data['Status']) && $data['Status'] === true;
            $this->apiMessage = $data['Message'] ?? '';
            $this->apiData = $data['Data'] ?? $data;

            if ($this->isSuccessful() && isset($this->apiData['MessageID'])) {
                $this->setMessageId((string) $this->apiData['MessageID']);
            }
        } else {
            // پاسخ غیر JSON (مانند AccountStatus که عدد برمی‌گرداند)
            $this->apiSuccess = is_numeric($content) || strpos($content, 'Success') !== false;
            $this->apiMessage = $this->apiSuccess ? 'با موفقیت انجام شد' : $content;
            $this->apiData = ['result' => $content];
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
     * تبدیل شماره‌ها به فرمت API
     *
     * @param  list<string>  $phones
     */
    private function toApiPhones(array $phones): string
    {
        return implode(',', $phones);
    }

    /**
     * تبدیل متغیرها به فرمت الگوی API
     *
     * @param  array<string, mixed>  $variables
     */
    private function toApiPattern(array $variables): string
    {
        return implode(',', $variables);
    }
}