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
 * @see https://farapayamak.ir/content/webservice/
 */
final class FaraPayamakDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'https://rest.payamak-panel.com/api/';

    /**
     * The status code returned in the API response body (e.g., `Value` field).
     */
    private string $apiStatusCode;

    /**
     * The return status from API
     */
    private int $apiRetStatus;

    /**
     * The string return status from API
     */
    private string $apiStrRetStatus;

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
     * دریافت موجودی ریالی از متد GetCredit2
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetCredit2', $this->credentials())
            ->throw();

        $this->processResponse($response);

        return (int) $this->apiStatusCode;
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
        throw UnsupportedMethodException::make($this->getDriverName(), method: 'otp', alternative: 'pattern');
    }

    /**
     * {@inheritdoc}
     * ارسال با الگو از طریق متد BaseServiceNumber
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = [
            'to' => $phones[0],
            'bodyId' => (int) $patternCode,
            'text' => $this->toApiPattern($variables),
        ];

        $this->execute('SendSMS/BaseServiceNumber', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک ساده از طریق متد SendSMS
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'from' => $from,
            'text' => $message,
            'to' => $this->toApiPhones($phones),
        ];

        $this->execute('SendSMS/SendSMS', $data);

        return $this;
    }

    /**
     * ارسال پیامک متناظر (نظیر به نظیر)
     * متد SendMultipleSMS
     * 
     * @param string $from شماره فرستنده
     * @param array<array{to: string, text: string}> $messages
     * @return array{success: bool, results: array, message: string}
     */
    public function sendMultiple(string $from, array $messages): array
    {
        $to = [];
        $text = [];

        foreach ($messages as $msg) {
            $to[] = $msg['to'];
            $text[] = $msg['text'];
        }

        $data = array_merge($this->credentials(), [
            'from' => $from,
            'to' => $to,
            'text' => $text,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asJson()
            ->acceptJson()
            ->post('SendMultipleSMS', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'results' => $this->apiData['Result'] ?? [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت وضعیت تحویل پیامک
     * متد GetDeliveries2
     * 
     * @param int $recId شناسه پیامک
     * @return array{success: bool, status: string, message: string}
     */
    public function getDeliveryStatus(int $recId): array
    {
        $data = array_merge($this->credentials(), [
            'recID' => $recId,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetDeliveries2', $data)
            ->throw();

        $this->processResponse($response);

        $statusMap = [
            '1' => 'ارسال شده به مخابرات',
            '2' => 'رسیده به گوشی',
            '3' => 'نرسیده به گوشی',
            '5' => 'خطای مخابراتی',
            '8' => 'خطای نامشخص',
            '16' => 'رسیده به مخابرات',
            '35' => 'لیست سیاه',
            '100' => 'نامشخص',
            '200' => 'ارسال شده',
            '300' => 'فیلتر شده',
            '400' => 'در لیست ارسال',
            '500' => 'عدم پذیرش',
        ];

        return [
            'success' => $this->isSuccessful(),
            'status' => $statusMap[$this->apiStatusCode] ?? $this->apiStatusCode,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست پیامک‌های ارسال یا دریافت
     * متد GetMessages
     * 
     * @param int $location 1=دریافتی, 2=ارسالی
     * @param string|null $from شماره فرستنده (اختیاری)
     * @param int $index اندیس شروع
     * @param int $count تعداد (حداکثر 100)
     * @return array{success: bool, messages: array, message: string}
     */
    public function getMessages(int $location = 1, ?string $from = null, int $index = 0, int $count = 100): array
    {
        $data = array_merge($this->credentials(), [
            'location' => $location,
            'from' => $from ?? '',
            'index' => $index,
            'count' => min($count, 100),
        ]);

        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetMessages', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'messages' => $this->apiData['Data'] ?? [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت تعرفه پایه
     * متد GetBasePrice
     */
    public function getBasePrice(): int
    {
        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetBasePrice', $this->credentials())
            ->throw();

        $this->processResponse($response);

        return (int) $this->apiStatusCode;
    }

    /**
     * دریافت لیست شماره‌های اختصاصی
     * متد GetUserNumbers
     */
    public function getUserNumbers(): array
    {
        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetUserNumbers', $this->credentials())
            ->throw();

        $this->processResponse($response);

        $numbers = [];
        if (isset($this->apiData['Data']) && is_array($this->apiData['Data'])) {
            foreach ($this->apiData['Data'] as $item) {
                $numbers[] = $item['Number'] ?? '';
            }
        }

        return $numbers;
    }

    /**
     * دریافت قیمت پیامک قبل از ارسال
     * متد GetSmsPrice
     * 
     * @param int $irancellCount تعداد شماره‌های ایرانسل
     * @param int $mtnCount تعداد شماره‌های همراه اول
     * @param string $text متن پیامک
     * @return array{success: bool, price: int, message: string}
     */
    public function getSmsPrice(int $irancellCount, int $mtnCount, string $text): array
    {
        $data = array_merge($this->credentials(), [
            'irancellCount' => $irancellCount,
            'mtnCount' => $mtnCount,
            'from' => $this->from,
            'text' => $text,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'SendSMS')
            ->asForm()
            ->acceptJson()
            ->post('GetSmsPrice', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'price' => (int) $this->apiStatusCode,
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای مدیریت گروه (Contacts) ====================

    /**
     * اضافه کردن گروه جدید در دفترچه تلفن
     * متد AddGroup
     * 
     * @param string $groupName نام گروه
     * @param string|null $description شرح
     * @param bool $showToChilds نمایش به زیرمجموعه
     * @return array{success: bool, group_id: int|null, message: string}
     */
    public function addGroup(string $groupName, ?string $description = null, bool $showToChilds = false): array
    {
        $data = array_merge($this->credentials(), [
            'GroupName' => $groupName,
            'Descriptions' => $description ?? '',
            'Showtochilds' => $showToChilds,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('AddGroup', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'group_id' => (int) $this->apiStatusCode,
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
     * دریافت لیست گروه‌های دفترچه تلفن
     * متد GetGroups
     * 
     * @return array{success: bool, groups: array, message: string}
     */
    public function getGroups(): array
    {
        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('GetGroups', $this->credentials())
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $groups = [];
            if (isset($this->apiData['Groupslist']) && is_array($this->apiData['Groupslist'])) {
                foreach ($this->apiData['Groupslist'] as $group) {
                    $groups[] = [
                        'GroupID' => $group['GroupID'] ?? null,
                        'GroupName' => $group['GroupName'] ?? '',
                        'Descriptions' => $group['Descriptions'] ?? '',
                        'ShowToChilds' => $group['ShowToChilds'] ?? false,
                    ];
                }
            }

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

    // ==================== متدهای مدیریت مخاطب (Contacts) ====================

    /**
     * اضافه کردن مخاطب جدید در دفترچه تلفن
     * متد AddContact
     * 
     * @param array{
     *     group_ids: string,
     *     first_name?: string,
     *     last_name?: string,
     *     mobile_number: string,
     *     email?: string,
     *     corporation?: string,
     *     phone?: string,
     *     fax?: string,
     *     birthdate?: string,
     *     gender?: int,
     *     province?: int,
     *     city?: int,
     *     address?: string,
     *     postal_code?: string,
     *     additional_text?: string,
     *     descriptions?: string
     * } $contact
     * @return array{success: bool, contact_id: int|null, message: string}
     */
    public function addContact(array $contact): array
    {
        $data = array_merge($this->credentials(), [
            'GroupIds' => $contact['group_ids'],
            'FirstName' => $contact['first_name'] ?? '',
            'LastName' => $contact['last_name'] ?? '',
            'NickName' => $contact['nick_name'] ?? '',
            'Corporation' => $contact['corporation'] ?? '',
            'MobileNumber' => $contact['mobile_number'],
            'Phone' => $contact['phone'] ?? '',
            'Fax' => $contact['fax'] ?? '',
            'Birthdate' => $contact['birthdate'] ?? '',
            'Email' => $contact['email'] ?? '',
            'Gender' => $contact['gender'] ?? 0,
            'Province' => $contact['province'] ?? 0,
            'City' => $contact['city'] ?? 0,
            'Address' => $contact['address'] ?? '',
            'PostalCode' => $contact['postal_code'] ?? '',
            'AdditionalDate' => $contact['additional_date'] ?? '',
            'AdditionalText' => $contact['additional_text'] ?? '',
            'Descriptions' => $contact['descriptions'] ?? '',
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('AddContact', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact_id' => (int) $this->apiStatusCode,
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
     * بررسی موجود بودن شماره در دفترچه تلفن
     * متد CheckMobileExist
     * 
     * @param string $mobileNumber شماره موبایل
     * @return array{success: bool, exists: bool, message: string}
     */
    public function checkMobileExists(string $mobileNumber): array
    {
        $data = array_merge($this->credentials(), [
            'MobileNumber' => $mobileNumber,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('CheckMobileExist', $data)
            ->throw();

        $this->processResponse($response);

        $exists = $this->apiStatusCode === '11';

        return [
            'success' => $this->isSuccessful(),
            'exists' => $exists,
            'message' => $exists ? 'شماره در دفترچه تلفن موجود است' : ($this->isSuccessful() ? 'شماره در دفترچه تلفن موجود نیست' : $this->getErrorMessage()),
        ];
    }

    /**
     * دریافت اطلاعات دفترچه تلفن
     * متد GetContacts
     * 
     * @param string|null $groupId شناسه گروه
     * @param int $from شماره ردیف شروع
     * @param int $count تعداد درخواستی
     * @return array{success: bool, contacts: array, message: string}
     */
    public function getContacts(?string $groupId = null, int $from = 0, int $count = 50): array
    {
        $data = array_merge($this->credentials(), [
            'GroupId' => $groupId,
            'Keyword' => $keyword ?? '',
            'From' => $from,
            'Count' => $count,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('GetContacts', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $contacts = [];
            if (isset($this->apiData['Grdlist']) && is_array($this->apiData['Grdlist'])) {
                foreach ($this->apiData['Grdlist'] as $contact) {
                    $contacts[] = [
                        'ContactID' => $contact['ContactID'] ?? null,
                        'FirstName' => $contact['FirstName'] ?? '',
                        'LastName' => $contact['LastName'] ?? '',
                        'MobileNumber' => $contact['MobileNumber'] ?? '',
                        'Email' => $contact['Email'] ?? '',
                        'GroupID' => $contact['GroupID'] ?? null,
                        'Corporation' => $contact['Corporation'] ?? '',
                        'Phone' => $contact['Phone'] ?? '',
                        'Fax' => $contact['Fax'] ?? '',
                        'Address' => $contact['Address'] ?? '',
                        'Birthdate' => $contact['Birthdate'] ?? '',
                        'Gender' => $contact['Gender'] ?? null,
                    ];
                }
            }

            return [
                'success' => true,
                'contacts' => $contacts,
                'message' => 'لیست مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'contacts' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * ویرایش مخاطب
     * متد ChangeContact
     * 
     * @param int $contactId شناسه مخاطب
     * @param array{
     *     mobile_number?: string,
     *     first_name?: string,
     *     last_name?: string,
     *     email?: string,
     *     corporation?: string,
     *     phone?: string,
     *     fax?: string,
     *     address?: string,
     *     postal_code?: string,
     *     additional_text?: string,
     *     descriptions?: string,
     *     contact_status?: int
     * } $data
     * @return array{success: bool, message: string}
     */
    public function editContact(int $contactId, array $data): array
    {
        $requestData = array_merge($this->credentials(), [
            'contactId' => $contactId,
            'MobileNumber' => $data['mobile_number'] ?? '',
            'FirstName' => $data['first_name'] ?? '',
            'LastName' => $data['last_name'] ?? '',
            'NickName' => $data['nick_name'] ?? '',
            'Corporation' => $data['corporation'] ?? '',
            'Phone' => $data['phone'] ?? '',
            'Fax' => $data['fax'] ?? '',
            'Email' => $data['email'] ?? '',
            'Gender' => $data['gender'] ?? 0,
            'Province' => $data['province'] ?? 0,
            'City' => $data['city'] ?? 0,
            'Address' => $data['address'] ?? '',
            'PostalCode' => $data['postal_code'] ?? '',
            'AdditionalText' => $data['additional_text'] ?? '',
            'Descriptions' => $data['descriptions'] ?? '',
            'ContactStatus' => $data['contact_status'] ?? 1,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('ChangeContact', $requestData)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت ویرایش شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * حذف مخاطب
     * متد RemoveContact
     * 
     * @param string $mobileNumber شماره موبایل
     * @return array{success: bool, message: string}
     */
    public function deleteContact(string $mobileNumber): array
    {
        $data = array_merge($this->credentials(), [
            'MobileNumber' => $mobileNumber,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('RemoveContact', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت حذف شد' : $this->getErrorMessage(),
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
        $contacts = $this->getContacts($groupId, 0, 1000);

        return [
            'success' => $contacts['success'],
            'count' => count($contacts['contacts']),
            'message' => $contacts['message'],
        ];
    }

    /**
     * دریافت مناسبت‌های مخاطب
     * متد GetContactEvents
     * 
     * @param int $contactId شناسه مخاطب
     * @return array{success: bool, events: array, message: string}
     */
    public function getContactEvents(int $contactId): array
    {
        $data = array_merge($this->credentials(), [
            'ContactId' => $contactId,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Contacts')
            ->asForm()
            ->acceptJson()
            ->post('GetContactEvents', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'events' => $this->apiData,
                'message' => 'مناسبت‌های مخاطب دریافت شد',
            ];
        }

        return [
            'success' => false,
            'events' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * ارسال پیامک به گروه
     * 
     * @param string $groupId شناسه گروه
     * @param string $message متن پیامک
     * @param string|null $from شماره فرستنده
     * @return array{success: bool, message_id: string|null, success_count: int, error?: string}
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        $contacts = $this->getContacts($groupId);

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
            if (!empty($contact['MobileNumber'])) {
                $mobiles[] = $contact['MobileNumber'];
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

    // ==================== متدهای مدیریت کاربران (Users) ====================

    /**
     * دریافت اعتبار کاربر
     * متد GetUserCredit
     * 
     * @param string $targetUserName نام کاربری کاربر مورد نظر
     * @return array{success: bool, credit: float, message: string}
     */
    public function getUserCredit(string $targetUserName): array
    {
        $data = array_merge($this->credentials(), [
            'TargetUserName' => $targetUserName,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Users')
            ->asForm()
            ->acceptJson()
            ->post('GetUserCredit', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'credit' => (float) $this->apiStatusCode,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * تغییر اعتبار کاربر
     * متد ChangeUserCredit
     * 
     * @param string $targetUserName نام کاربری کاربر مورد نظر
     * @param float $amount مقدار اعتبار (مثبت برای افزایش، منفی برای کاهش)
     * @param string $description شرح
     * @param bool $getTax لحاظ کردن مالیات
     * @return array{success: bool, message: string}
     */
    public function changeUserCredit(string $targetUserName, float $amount, string $description, bool $getTax = false): array
    {
        $data = array_merge($this->credentials(), [
            'Amount' => $amount,
            'Description' => $description,
            'TargetUserName' => $targetUserName,
            'GetTax' => $getTax,
        ]);

        $response = Http::baseUrl($this->baseUrl . 'Users')
            ->asForm()
            ->acceptJson()
            ->post('ChangeUserCredit', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'اعتبار کاربر با موفقیت تغییر کرد' : $this->getErrorMessage(),
        ];
    }

    /**
     * احراز هویت و دریافت ID کاربر
     * متد AuthenticateUser
     * 
     * @return array{success: bool, user_id: int, message: string}
     */
    public function authenticateUser(): array
    {
        $response = Http::baseUrl($this->baseUrl . 'Users')
            ->asForm()
            ->acceptJson()
            ->post('AuthenticateUser', $this->credentials())
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'user_id' => (int) $this->apiStatusCode,
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای غیرقابل پشتیبانی ====================

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        return $this->addGroup($name, $description);
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        throw UnsupportedMethodException::make($this->getDriverName(), 'editGroup');
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnsupportedMethodException
     */
    public function deleteGroup(string $groupId): array
    {
        throw  UnsupportedMethodException::make($this->getDriverName(), 'deleteGroup');
    }

    // ==================== متدهای خصوصی ====================

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiRetStatus === 1 && mb_strlen($this->apiStatusCode) >= 1;
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorMessage(): string
    {
        if (!$this->isSuccessful() && $this->apiStrRetStatus !== 'Ok') {
            return $this->apiStrRetStatus;
        }

        return match ($this->apiStatusCode) {
            '-10' => 'متن حاوی لینک می‌باشد',
            '-7' => 'خطایی در شماره فرستنده رخ داده است با پشتیبانی تماس بگیرید',
            '-6' => 'خطای داخلی رخ داده است با پشتیبانی تماس بگیرید',
            '-5' => 'متن ارسالی باتوجه به متغیرهای مشخص شده در متن پیشفرض همخوانی ندارد',
            '-4' => 'کد متن ارسالی صحیح نمی‌باشد و یا توسط مدیر سامانه تأیید نشده است',
            '-3' => 'خط ارسالی در سیستم تعریف نشده است، با پشتیبانی سامانه تماس بگیرید',
            '-2' => 'محدودیت تعداد شماره، محدودیت هربار ارسال یک شماره موبایل می‌باشد',
            '-1' => 'دسترسی برای استفاده از این وبسرویس غیرفعال است. با پشتیبانی تماس بگیرید',
            '0' => 'نام کاربری یا رمزعبور صحیح نمی‌باشد',
            '2' => 'اعتبار کافی نمی‌باشد',
            '3' => 'محدودیت در ارسال روزانه',
            '4' => 'محدودیت در حجم ارسال',
            '5' => 'شماره فرستنده معتبر نمی‌باشد',
            '6' => 'سامانه درحال بروزرسانی می‌باشد',
            '7' => 'متن حاوی کلمه فیلتر شده می‌باشد',
            '9' => 'ارسال از خطوط عمومی از طریق وب سرویس امکان‌پذیر نمی‌باشد',
            '10' => 'کاربر موردنظر فعال نمی‌باشد',
            '11' => 'ارسال نشده',
            '12' => 'مدارک کاربر کامل نمی‌باشد',
            '14' => 'متن حاوی لینک می‌باشد',
            '15' => 'ارسال به بیش از 1 شماره همراه بدون درج "لغو11" ممکن نیست',
            '16' => 'شماره گیرنده‌ای یافت نشد',
            '17' => 'متن پیامک خالی می‌باشد',
            '35' => 'شماره در لیست سیاه مخابرات می‌باشد',
            default => $this->apiStatusCode === '' ? 'ارسال موفق' : "خطای ناشناخته با کد {$this->apiStatusCode} رخ داده است"
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->apiStatusCode;
    }

    /**
     * Executes the API request to the specified endpoint with given data.
     *
     * @param  string  $endpoint
     * @param  array<string, mixed>  $data
     */
    private function execute(string $endpoint, array $data): void
    {
        $response = Http::baseUrl($this->baseUrl)
            ->asForm()
            ->acceptJson()
            ->post($endpoint, $data)
            ->throw();

        $this->processResponse($response);
    }

    /**
     * Process API response
     *
     * @param  \Illuminate\Http\Client\Response  $response
     */
    private function processResponse($response): void
    {
        $this->apiStatusCode = (string) $response->json('Value');
        $this->apiRetStatus = (int) $response->json('RetStatus', 0);
        $this->apiStrRetStatus = (string) $response->json('StrRetStatus', '');
        $this->apiData = $response->json();

        if ($this->isSuccessful() && is_numeric($this->apiStatusCode) && strlen($this->apiStatusCode) >= 15) {
            $this->setMessageId($this->apiStatusCode);
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
     * Transforms variables into the API's expected pattern structure.
     *
     * @param  array<string, mixed>  $variables
     */
    private function toApiPattern(array $variables): string
    {
        return implode(';', $variables);
    }

    /**
     * Transforms phones into the API's expected phone structure.
     *
     * @param  list<string>  $phones
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