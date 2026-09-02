<?php

namespace App\Domain\Sms\Services;

use App\Domain\Identity\Services\V2PhoneNormalizer;
use App\Domain\Sms\Contracts\V2SmsProvider;
use App\Domain\Sms\Values\V2SmsDeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use SensitiveParameter;
use Throwable;

final class V2FourSSmsProvider implements V2SmsProvider
{
    public function __construct(private readonly V2PhoneNormalizer $phones)
    {
    }

    public function deliver(
        #[SensitiveParameter] string $canonicalPhone,
        #[SensitiveParameter] string $verificationCode
    ): V2SmsDeliveryResult {
        $endpoint = (string) config('v2_sms.fours.endpoint');
        $userId = (string) config('v2_sms.fours.cp_userid');
        $password = (string) config('v2_sms.fours.cp_password');
        $userAgent = trim((string) config('v2_sms.fours.user_agent'));
        $timeout = (int) config('v2_sms.fours.timeout_seconds', 10);
        if (
            ! $this->validEndpoint($endpoint)
            || $userId === ''
            || $password === ''
            || $userAgent === ''
            || $timeout < 1
            || $timeout > 30
            || ! preg_match('/\A[0-9]{6}\z/', $verificationCode)
        ) {
            return V2SmsDeliveryResult::failed('provider_configuration_unavailable');
        }

        try {
            $address = $this->phones->toDomestic($canonicalPhone);
        } catch (\InvalidArgumentException) {
            return V2SmsDeliveryResult::failed('provider_rejected');
        }

        try {
            $response = Http::asForm()
                ->withUserAgent($userAgent)
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->post($endpoint, [
                    'carrier_id' => '99',
                    'message' => $this->message($verificationCode),
                    'address' => $address,
                    'send_date' => '',
                    'urlshorterflg' => '0',
                    'cp_userid' => $userId,
                    'cp_password' => $password,
                ]);
        } catch (ConnectionException) {
            return V2SmsDeliveryResult::unknown('provider_timeout');
        } catch (Throwable) {
            return V2SmsDeliveryResult::unknown('provider_unavailable');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return V2SmsDeliveryResult::failed('provider_auth_failure');
        }
        if ($response->serverError() || $response->status() === 429) {
            return V2SmsDeliveryResult::unknown('provider_unavailable');
        }
        if ($response->clientError()) {
            return V2SmsDeliveryResult::failed('provider_rejected');
        }

        try {
            $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return V2SmsDeliveryResult::unknown('provider_malformed_response');
        }
        if (! is_array($payload) || ! is_string($payload['result'] ?? null)) {
            return V2SmsDeliveryResult::unknown('provider_malformed_response');
        }
        if ($payload['result'] === 'ERROR') {
            return V2SmsDeliveryResult::failed('provider_rejected');
        }
        $requestId = $payload['request_id'] ?? null;
        $requestDate = $payload['request_date'] ?? null;
        if (
            $payload['result'] !== 'SUCCESS'
            || ! is_string($requestId)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $requestId)
            || ! is_string($requestDate)
            || trim($requestDate) === ''
        ) {
            return V2SmsDeliveryResult::unknown('provider_malformed_response');
        }

        return V2SmsDeliveryResult::accepted($requestId);
    }

    private function validEndpoint(string $endpoint): bool
    {
        return filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && parse_url($endpoint, PHP_URL_SCHEME) === 'https';
    }

    private function message(#[SensitiveParameter] string $verificationCode): string
    {
        return (string) config('app.name').'の認証コードは「'.$verificationCode."」です。\n\n".
            "有効期限は5分です。\n\n".
            'このコードを第三者に教えないでください。';
    }
}
