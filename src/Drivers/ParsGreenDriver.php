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
    ) {
    }

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