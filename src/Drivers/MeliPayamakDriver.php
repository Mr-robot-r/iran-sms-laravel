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
 * @see https://github.com/Melipayamak/melipayamak-php
 */
final class MelipayamakDriver extends Driver
{
    /**
     * The base URL for the REST API.
     */
    private string $baseUrlRest = 'https://rest.payamak-panel.com/api/SendSMS/';

    /**
     * The base URL for the SOAP API (برای متدهای پیشرفته).
     */
    private string $baseUrlSoap = 'https://soap.payamak-panel.com/api/SendSMS/';

    /**
     * The status code returned in the API response body.
     */
    private int $apiStatusCode;

    /**
     * The message returned in the API response body.
     */
    private string $apiMessage;

    /**
     * The data returned from API.
     */
    private array $apiData;

    /**
     * The return value from API.
     */
    private string $returnValue;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    /**
     * Get driver name for exceptions
     */
    protected function getDriverName(): string
    {
        return 'Melipayamak';
    }

    // ==================== متدهای اجباری کلاس Driver ====================

    /**
     * {@inheritdoc}
     * دریافت اعتبار از متد GetCredit
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('GetCredit', [
                'username' => $this->username,
                'password' => $this->password,
            ])
            ->throw();

        $this->processResponse($response);

        return (int) $this->returnValue;
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
     * ارسال با الگو از طریق متد SendByBaseNumber
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternPhones($phones);
        $this->validatePatternVariables($variables);

        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'text' => $patternCode,
            'to' => $phones[0],
            'bodyId' => (int) $patternCode,
        ];

        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('SendByBaseNumber', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $this->setMessageId($this->returnValue);
            $this->setSuccessCount(1);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * ارسال ساده با متد SendSimpleSMS
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'text' => $message,
            'to' => $this->toApiPhones($phones),
            'from' => $from,
        ];

        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('SendSimpleSMS', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $this->setMessageId($this->returnValue);
            $this->setSuccessCount(count($phones));
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 200 && (is_numeric($this->returnValue) || $this->returnValue === 'true');
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorMessage(): string
    {
        return $this->apiMessage ?: 'خطا در ارتباط با سرور';
    }

    /**
     * {@inheritdoc}
     */
    protected function getErrorCode(): string|int
    {
        return $this->apiStatusCode;
    }

    // ==================== متدهای مدیریت گروه (اجباری کلاس Driver) ====================

    /**
     * {@inheritdoc}
     * ایجاد گروه جدید - متد addGroup
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'groupName' => $name,
            'Descriptions' => $description ?? '',
            'ShowToChilds' => false,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('AddGroup', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'group_id' => (string) $this->returnValue,
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
     *
     * @throws UnsupportedMethodException
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        throw UnsupportedMethodException::make(
            $this->getDriverName(),
            "editGroup"
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnsupportedMethodException
     */
    public function deleteGroup(string $groupId): array
    {
        throw UnsupportedMethodException::make(
            $this->getDriverName(),
            "deleteGroup"
        );
    }

    /**
     * {@inheritdoc}
     * دریافت لیست گروه‌ها - متد getGroups
     */
    public function getGroups(): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('GetGroups', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $groups = json_decode($this->returnValue, true) ?? [];
            $formattedGroups = array_map(function ($group) {
                return [
                    'GroupID' => (string) ($group['GroupID'] ?? $group['Id'] ?? ''),
                    'Name' => $group['GroupName'] ?? $group['Name'] ?? '',
                    'Description' => $group['Descriptions'] ?? '',
                    'IsActive' => true,
                ];
            }, $groups);

            return [
                'success' => true,
                'groups' => $formattedGroups,
                'message' => 'لیست گروه‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'groups' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای مدیریت مخاطب (اجباری کلاس Driver) ====================

    /**
     * {@inheritdoc}
     * اضافه کردن مخاطب جدید - متد addContact
     */
    public function addContact(array $contact): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'options' => [
                'GroupID' => (int) $contact['group_id'],
                'Mobile' => $contact['mobile'],
                'FirstName' => $contact['first_name'] ?? '',
                'LastName' => $contact['last_name'] ?? '',
                'Email' => $contact['email'] ?? '',
                'Birthday' => $contact['birthday'] ?? '',
                'Anniversary' => $contact['anniversary'] ?? '',
                'Corporation' => $contact['corporation'] ?? '',
                'Job' => $contact['job'] ?? '',
                'Address' => $contact['address'] ?? '',
                'Desc' => $contact['desc'] ?? '',
                'ProvinceId' => $contact['province_id'] ?? 0,
                'CityId' => $contact['city_id'] ?? 0,
                'Gender' => $contact['gender'] ?? '',
            ],
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('AddContact', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'contact_id' => (string) $this->returnValue,
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
     * دریافت لیست مخاطبین - متد getContacts
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'groupId' => (int) ($groupId ?? 0),
            'keyword' => '',
            'from' => ($page - 1) * $perPage,
            'count' => $perPage,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('GetContacts', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $result = json_decode($this->returnValue, true);
            $contactsData = $result['Contacts'] ?? [];
            $total = $result['Total'] ?? count($contactsData);

            $contacts = array_map(function ($contact) {
                return [
                    'ContactID' => (string) ($contact['ContactID'] ?? ''),
                    'FirstName' => $contact['FirstName'] ?? '',
                    'LastName' => $contact['LastName'] ?? '',
                    'MobileNumbers' => $contact['Mobile'] ?? '',
                    'Email' => $contact['Email'] ?? '',
                    'GroupID' => (string) ($contact['GroupID'] ?? ''),
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
     * حذف مخاطب - متد remove
     */
    public function deleteContact(string $contactId): array
    {
        // در ملی پیامک حذف بر اساس شماره موبایل است نه contact_id
        // برای این کار ابتدا باید مخاطب را پیدا کنیم
        $contacts = $this->getContacts(null, 1, 1000);

        if (!$contacts['success']) {
            return [
                'success' => false,
                'message' => 'امکان دریافت لیست مخاطبین وجود ندارد',
            ];
        }

        $mobile = null;
        foreach ($contacts['contacts'] as $contact) {
            if ($contact['ContactID'] === $contactId) {
                $mobile = $contact['MobileNumbers'];
                break;
            }
        }

        if (!$mobile) {
            return [
                'success' => false,
                'message' => 'مخاطب مورد نظر یافت نشد',
            ];
        }

        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'mobileNumber' => $mobile,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('RemoveContact', $data)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت حذف شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * {@inheritdoc}
     * دریافت تعداد مخاطبین گروه
     */
    public function getContactsCount(string $groupId): array
    {
        $contacts = $this->getContacts($groupId, 1, 1);

        return [
            'success' => $contacts['success'],
            'count' => $contacts['total'] ?? 0,
            'message' => $contacts['message'],
        ];
    }

    /**
     * {@inheritdoc}
     * ارسال پیامک به گروه
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

    // ==================== متدهای اضافی (اختیاری) ====================

    /**
     * دریافت وضعیت تحویل پیامک - متد IsDelivered
     */
    public function isDelivered($recId): array
    {
        $ids = is_array($recId) ? implode(',', $recId) : $recId;

        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('GetDeliveries', [
                'username' => $this->username,
                'password' => $this->password,
                'recId' => $ids,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            return [
                'success' => true,
                'delivered' => $this->returnValue === 'true',
                'message' => 'وضعیت پیامک دریافت شد',
            ];
        }

        return [
            'success' => false,
            'delivered' => null,
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت لیست شماره‌های اختصاصی - متد GetNumbers
     */
    public function getNumbers(): array
    {
        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('GetNumbers', [
                'username' => $this->username,
                'password' => $this->password,
            ])
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $numbers = explode(',', $this->returnValue);
            return [
                'success' => true,
                'numbers' => $numbers,
                'message' => 'لیست شماره‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'numbers' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت پیامک‌های دریافتی - متد GetMessages
     */
    public function getMessages(int $location = 1, int $fromIndex = 0, int $count = 50, ?string $from = null): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'location' => $location,
            'from' => $fromIndex,
            'count' => $count,
        ];

        if ($from) {
            $data['from'] = $from;
        }

        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('GetMessages', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $messages = json_decode($this->returnValue, true) ?? [];
            return [
                'success' => true,
                'messages' => $messages,
                'message' => 'لیست پیامک‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'messages' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت تعرفه پایه - متد GetBasePrice
     */
    public function getBasePrice(): int
    {
        $response = Http::baseUrl($this->baseUrlRest)
            ->asJson()
            ->post('GetBasePrice', [
                'username' => $this->username,
                'password' => $this->password,
            ])
            ->throw();

        $this->processResponse($response);

        return (int) $this->returnValue;
    }

    /**
     * بررسی موجود بودن شماره در دفترچه تلفن
     */
    public function checkMobileExists(string $mobile): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'mobileNumber' => $mobile,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('CheckMobileExist', $data)
            ->throw();

        $this->processResponse($response);

        $exists = $this->returnValue !== '0' && !empty($this->returnValue);

        return [
            'success' => true,
            'exists' => $exists,
            'contact_id' => $exists ? (int) $this->returnValue : null,
            'message' => $exists ? 'شماره در دفترچه تلفن موجود است' : 'شماره در دفترچه تلفن موجود نیست',
        ];
    }

    /**
     * ویرایش مخاطب - متد editContact
     */
    public function editContact(array $data): array
    {
        $requestData = [
            'username' => $this->username,
            'password' => $this->password,
            'options' => [
                'ContactID' => (int) $data['contact_id'],
                'GroupID' => (int) ($data['group_id'] ?? 0),
                'Mobile' => $data['mobile'] ?? '',
                'FirstName' => $data['first_name'] ?? '',
                'LastName' => $data['last_name'] ?? '',
                'Email' => $data['email'] ?? '',
                'Birthday' => $data['birthday'] ?? '',
                'Anniversary' => $data['anniversary'] ?? '',
                'Corporation' => $data['corporation'] ?? '',
                'Job' => $data['job'] ?? '',
                'Address' => $data['address'] ?? '',
                'Desc' => $data['desc'] ?? '',
            ],
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('EditContact', $requestData)
            ->throw();

        $this->processResponse($response);

        return [
            'success' => $this->isSuccessful(),
            'message' => $this->isSuccessful() ? 'مخاطب با موفقیت ویرایش شد' : $this->getErrorMessage(),
        ];
    }

    /**
     * دریافت اطلاعات مناسبت‌های فرد - متد getEvents
     */
    public function getEvents(int $contactId): array
    {
        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'contactId' => $contactId,
        ];

        $response = Http::baseUrl($this->baseUrlSoap)
            ->asJson()
            ->post('GetEvents', $data)
            ->throw();

        $this->processResponse($response);

        if ($this->isSuccessful()) {
            $events = json_decode($this->returnValue, true) ?? [];
            return [
                'success' => true,
                'events' => $events,
                'message' => 'اطلاعات مناسبت‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'events' => [],
            'message' => $this->getErrorMessage(),
        ];
    }

    // ==================== متدهای خصوصی ====================

    /**
     * Process API response
     */
    private function processResponse($response): void
    {
        $this->apiStatusCode = $response->status();
        $this->apiMessage = '';

        $content = $response->body();
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['Value'])) {
            $this->returnValue = (string) $data['Value'];
            $this->apiMessage = $data['RetStatus'] ?? '';
        } else {
            $this->returnValue = $content;
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

    /**
     * تبدیل شماره‌ها به فرمت API
     */
    private function toApiPhones(array $phones): string
    {
        return implode(',', $phones);
    }
}