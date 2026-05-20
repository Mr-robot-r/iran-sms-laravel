<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Drivers;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Exceptions\InvalidPatternStructureException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * @internal
 *
 * @see https://www.parsgreen.com/Content/files/Doc/sms/Web-Service/restful-help.pdf
 */
final class ParsGreenDriver extends Driver
{
    /**
     * The base URL for the API.
     */
    private string $baseUrl = 'http://sms.parsgreen.ir/Apiv2/';

    /**
     * The sent status returned in the API response body.
     */
    private bool $apiStatus;

    /**
     * The status code returned in the API response body.
     */
    private int $apiStatusCode;

    /**
     * The error message returned in the API response body.
     */
    private string $apiErrorMessage;

    public function __construct(
        private readonly string $token,
        private readonly string $from,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function credit(): int
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('User/credit')
            ->throw();

        return (int) $response->json('Amount');
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
     */
    protected function sendOtp(string $phone, string $code, string $from): static
    {
        $data = [
            'Mobile' => $phone,
            'SmsCode' => $code,
            'AddName' => false,
        ];

        $this->execute('Message/SendOtp', $data);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendPattern(array $phones, string $patternCode, array $variables, string $from): static
    {
        $this->validatePatternVariables($variables);

        // ساخت متن نهایی از الگو (جایگزینی %% با مقادیر)
        $message = $patternCode;
        foreach ($variables as $value) {
            $message = preg_replace('/%%/', (string) $value, $message, 1);
        }

        return $this->sendText($phones, $message, $from);
    }

    /**
     * {@inheritdoc}
     */
    protected function sendText(array $phones, string $message, string $from): static
    {
        $data = [
            'SmsBody' => $message,
            'Mobiles' => implode(',', $phones),
            'SmsNumber' => $from,
        ];

        $this->execute('Message/SendSms', $data);

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

    // ==================== متدهای مدیریت گروه (صفحات 8-11 مستندات) ====================

    /**
     * {@inheritdoc}
     * متد GroupAdd - صفحه 8
     */
    public function createGroup(string $name, ?string $description = null): array
    {
        $data = [
            'Name' => $name,
            'Description' => $description ?? '',
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/GroupAdd', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        if ($success) {
            return [
                'success' => true,
                'group_id' => $response->json('GroupID'),
                'message' => 'گروه با موفقیت ایجاد شد',
            ];
        }

        return [
            'success' => false,
            'group_id' => null,
            'message' => $response->json('R_Error') ?? 'خطا در ایجاد گروه',
        ];
    }

    /**
     * {@inheritdoc}
     * متد GroupEdit - صفحه 9
     */
    public function editGroup(string $groupId, string $name, ?string $description = null): array
    {
        $data = [
            'GroupID' => $groupId,
            'Name' => $name,
            'Description' => $description ?? '',
            'IsActive' => true,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/GroupEdit', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        return [
            'success' => $success,
            'message' => $success ? 'گروه با موفقیت ویرایش شد' : ($response->json('R_Error') ?? 'خطا در ویرایش گروه'),
        ];
    }

    /**
     * {@inheritdoc}
     * متد GroupDelete - صفحه 10
     */
    public function deleteGroup(string $groupId): array
    {
        $data = ['GroupID' => $groupId];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/GroupDelete', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        return [
            'success' => $success,
            'message' => $success ? 'گروه با موفقیت حذف شد' : ($response->json('R_Error') ?? 'خطا در حذف گروه'),
        ];
    }

    /**
     * {@inheritdoc}
     * متد Grouplist - صفحه 11
     */
    public function getGroups(): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/Grouplist')
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        if ($success) {
            return [
                'success' => true,
                'groups' => $response->json('Data') ?? [],
                'message' => 'لیست گروه‌ها دریافت شد',
            ];
        }

        return [
            'success' => false,
            'groups' => [],
            'message' => $response->json('R_Error') ?? 'خطا در دریافت لیست گروه‌ها',
        ];
    }

    // ==================== متدهای مدیریت مخاطب (صفحات 12-15 مستندات) ====================

    /**
     * {@inheritdoc}
     * متد ContactAdd - صفحه 15
     */
    public function addContact(array $contact): array
    {
        $data = [
            'FirstName' => $contact['first_name'] ?? '',
            'LastName' => $contact['last_name'] ?? '',
            'Corporation' => $contact['corporation'] ?? '',
            'MobileNumbers' => $contact['mobile'],
            'PhoneNumbers' => $contact['phone'] ?? '',
            'FaxNumbers' => $contact['fax'] ?? '',
            'BirthDate' => $contact['birth_date'] ?? 0,
            'Email' => $contact['email'] ?? '',
            'Gender' => $contact['gender'] ?? 0,
            'Address' => $contact['address'] ?? '',
            'PostalCode' => $contact['postal_code'] ?? '',
            'Descriptions' => $contact['description'] ?? '',
            'welcomeText' => $contact['welcome_text'] ?? '',
            'GroupID' => $contact['group_id'],
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/ContactAdd', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        if ($success) {
            return [
                'success' => true,
                'contact_id' => $response->json('ContactID'),
                'message' => 'مخاطب با موفقیت اضافه شد',
            ];
        }

        return [
            'success' => false,
            'contact_id' => null,
            'message' => $response->json('R_Error') ?? 'خطا در اضافه کردن مخاطب',
        ];
    }

    /**
     * {@inheritdoc}
     * متد Contactlist - صفحه 12
     */
    public function getContacts(?string $groupId = null, int $page = 1, int $perPage = 50): array
    {
        $data = [
            'MobileNumber' => '',
            'Page' => $page,
            'PageSize' => $perPage,
        ];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/ContactList', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        if ($success) {
            $contacts = $response->json('Data') ?? [];
            
            // اگر groupId مشخص شده، فیلتر کنیم
            if ($groupId && !empty($contacts)) {
                $contacts = array_filter($contacts, function($contact) use ($groupId) {
                    return ($contact['GroupID'] ?? '') === $groupId;
                });
                $contacts = array_values($contacts);
            }
            
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
            'message' => $response->json('R_Error') ?? 'خطا در دریافت لیست مخاطبین',
        ];
    }

    /**
     * {@inheritdoc}
     * متد ContactDelete - صفحه 14
     */
    public function deleteContact(string $contactId): array
    {
        $data = ['ContactID' => $contactId];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/ContactDelete', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        return [
            'success' => $success,
            'message' => $success ? 'مخاطب با موفقیت حذف شد' : ($response->json('R_Error') ?? 'خطا در حذف مخاطب'),
        ];
    }

    /**
     * {@inheritdoc}
     * متد ContactCount - صفحه 13
     */
    public function getContactsCount(string $groupId): array
    {
        $data = ['GroupID' => $groupId];

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->credentials())
            ->asJson()
            ->post('Contact/ContactCount', $data)
            ->throw();

        $success = $response->json('R_Success') ?? false;
        
        if ($success) {
            return [
                'success' => true,
                'count' => (int) ($response->json('count') ?? 0),
                'message' => 'تعداد مخاطبین دریافت شد',
            ];
        }

        return [
            'success' => false,
            'count' => 0,
            'message' => $response->json('R_Error') ?? 'خطا در دریافت تعداد مخاطبین',
        ];
    }

    /**
     * {@inheritdoc}
     * ترکیبی از ContactList و SendSms
     */
    public function sendToGroup(string $groupId, string $message, ?string $from = null): array
    {
        // ابتدا لیست مخاطبین گروه را بگیر
        $contacts = $this->getContacts($groupId, 1, 1000);
        
        if (!$contacts['success']) {
            return [
                'success' => false,
                'message_id' => null,
                'success_count' => 0,
                'error' => $contacts['message'],
            ];
        }

        // استخراج شماره موبایل‌ها
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

        // ارسال پیامک به همه شماره‌ها
        $this->sendText($mobiles, $message, $from ?? $this->from);
        
        return [
            'success' => $this->isSuccessful(),
            'message_id' => $this->getMessageId(),
            'success_count' => $this->getSuccessCount(),
            'error' => $this->error(),
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
            ->asJson()
            ->post($endpoint, $data)
            ->throw();

        $this->apiStatus = $response->json('R_Success') ?? false;
        $this->apiStatusCode = $response->json('R_Code') ?? 0;
        $this->apiErrorMessage = $response->json('R_Error') ?? $response->json('R_Message') ?? '';

        if ($this->apiStatus) {
            $responseData = $response->json('Data');
            if ($responseData) {
                $this->setMessageId($responseData['ReqID'] ?? null);
                $this->setSuccessCount($responseData['SuccessCount'] ?? 0);
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