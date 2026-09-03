<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class V2FincodeClient
{
    /** @return array<string, mixed> */
    public function createCustomer(string $customerId, string $idempotencyKey): array
    {
        return $this->request('post', '/v1/customers', ['id' => $customerId], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveCard(string $customerId, string $cardId): array
    {
        return $this->request(
            'get',
            '/v1/customers/'.rawurlencode($customerId).'/cards/'.rawurlencode($cardId)
        );
    }

    /** @return array<string, mixed> */
    public function createCardPaymentMethod(
        string $customerId,
        #[SensitiveParameter] string $cardToken,
        bool $makeDefault,
        string $returnUrl,
        string $failureUrl,
        string $registrationPublicId,
        string $idempotencyKey
    ): array {
        return $this->request(
            'post',
            '/v1/customers/'.rawurlencode($customerId).'/payment_methods',
            [
                'pay_type' => 'Card',
                'default_flag' => $makeDefault ? '1' : '0',
                'return_url' => $returnUrl,
                'return_url_on_failure' => $failureUrl,
                'client_field_1' => $registrationPublicId,
                'card' => [
                    'token' => $cardToken,
                    'tds_type' => '2',
                    'tds2_type' => '2',
                ],
            ],
            $idempotencyKey
        );
    }

    /** @return array<string, mixed> */
    public function retrieveCardPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        return $this->request(
            'get',
            '/v1/customers/'.rawurlencode($customerId)
                .'/payment_methods/'.rawurlencode($paymentMethodId)
                .'?pay_type=Card'
        );
    }

    public function deleteCard(string $customerId, string $cardId): void
    {
        $this->request(
            'delete',
            '/v1/customers/'.rawurlencode($customerId).'/cards/'.rawurlencode($cardId)
        );
    }

    /** @return array<string, mixed> */
    public function createCardPayment(
        string $orderId,
        int $amount,
        string $idempotencyKey
    ): array {
        return $this->request('post', '/v1/payments', [
            'id' => $orderId,
            'pay_type' => 'Card',
            'job_code' => 'CAPTURE',
            'amount' => (string) $amount,
            'tds_type' => '2',
            'tds2_type' => '2',
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function executeSavedCard(
        string $orderId,
        string $accessId,
        string $customerId,
        string $cardId,
        string $returnUrl,
        string $failureUrl,
        string $idempotencyKey
    ): array {
        return $this->request('put', '/v1/payments/'.rawurlencode($orderId), [
            'pay_type' => 'Card',
            'access_id' => $accessId,
            'customer_id' => $customerId,
            'card_id' => $cardId,
            'method' => '1',
            'return_url' => $returnUrl,
            'return_url_on_failure' => $failureUrl,
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function createRedirectSession(
        string $orderId,
        string $payType,
        int $amount,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey
    ): array {
        $payload = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'transaction' => [
                'order_id' => $orderId,
                'pay_type' => [$payType],
                'amount' => (string) $amount,
            ],
        ];
        if ($payType === 'Paypay') {
            $payload['paypay'] = ['job_code' => 'CAPTURE'];
        } elseif ($payType === 'Konbini') {
            $payload['konbini'] = [
                'payment_term_day' => (string) config('v2_fincode.konbini_payment_term_days'),
                'konbini_reception_mail_send_flag' => '0',
            ];
        } elseif ($payType === 'Virtualaccount') {
            $payload['virtualaccount'] = [
                'payment_term_day' => (string) config('v2_fincode.virtual_account_payment_term_days'),
                'virtualaccount_reception_mail_send_flag' => '0',
            ];
        } else {
            throw new V2FincodeException(
                'PAYMENT_METHOD_UNSUPPORTED',
                422,
                'The payment method is unsupported.'
            );
        }

        return $this->request('post', '/v1/sessions', $payload, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrievePayment(string $orderId, string $payType): array
    {
        return $this->request(
            'get',
            '/v1/payments/'.rawurlencode($orderId).'?pay_type='.rawurlencode($payType)
        );
    }

    /** @return array<string, mixed> */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null
    ): array {
        $request = $this->pendingRequest($idempotencyKey);
        try {
            $response = match ($method) {
                'get' => $request->get($this->baseUrl().$path),
                'post' => $request->post($this->baseUrl().$path, $payload),
                'put' => $request->put($this->baseUrl().$path, $payload),
                'delete' => $request->delete($this->baseUrl().$path),
                default => throw new V2FincodeException(
                    'FINCODE_OPERATION_INVALID',
                    500,
                    'The fincode operation is invalid.'
                ),
            };
        } catch (ConnectionException) {
            throw new V2FincodeException(
                'FINCODE_TIMEOUT',
                503,
                'The payment provider did not confirm the request.',
                true
            );
        } catch (V2FincodeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new V2FincodeException(
                'FINCODE_TRANSPORT_FAILURE',
                503,
                'The payment provider is unavailable.',
                true
            );
        }

        return $this->decode($response);
    }

    private function pendingRequest(?string $idempotencyKey): PendingRequest
    {
        if (config('v2_fincode.enabled') !== true) {
            throw new V2FincodeException(
                'FINCODE_ACTIVATION_DEFERRED',
                503,
                'The payment provider is not activated.'
            );
        }
        $baseUrl = config('v2_fincode.base_url');
        $secret = config('v2_fincode.secret_api_key');
        $timeout = config('v2_fincode.timeout_seconds');
        if (
            ! is_string($baseUrl)
            || ! in_array($baseUrl, ['https://api.test.fincode.jp', 'https://api.fincode.jp'], true)
            || ! is_string($secret)
            || $secret === ''
            || ! is_int($timeout)
            || $timeout < 1
            || $timeout > 30
        ) {
            throw new V2FincodeException(
                'FINCODE_CONFIGURATION_UNAVAILABLE',
                503,
                'The payment provider configuration is unavailable.'
            );
        }
        if (
            app()->environment('production')
            && $baseUrl === 'https://api.test.fincode.jp'
            && config('v2_fincode.allow_test_in_production') !== true
        ) {
            throw new V2FincodeException(
                'FINCODE_PRODUCTION_ENDPOINT_REQUIRED',
                503,
                'The payment provider configuration is unavailable.'
            );
        }

        $request = Http::acceptJson()
            ->asJson()
            ->withToken($this->secret($secret))
            ->timeout($timeout)
            ->connectTimeout($timeout);
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['idempotent_key' => $idempotencyKey]);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            $providerHttpStatus = $response->status();
            throw new V2FincodeException(
                $response->serverError() ? 'FINCODE_PROVIDER_UNAVAILABLE' : 'FINCODE_PROVIDER_REJECTED',
                $response->serverError() || $providerHttpStatus === 429 ? 503 : 422,
                $response->serverError()
                    ? 'The payment provider is unavailable.'
                    : 'The payment provider rejected the request.',
                $response->serverError() || $providerHttpStatus === 429,
                $providerHttpStatus,
                $this->safeProviderErrorCode($response)
            );
        }
        if ($response->status() === 204 || $response->body() === '') {
            return [];
        }
        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new V2FincodeException(
                'FINCODE_RESPONSE_INVALID',
                503,
                'The payment provider returned an invalid response.',
                true
            );
        }

        return $decoded;
    }

    private function safeProviderErrorCode(Response $response): ?string
    {
        $decoded = $response->json();
        $errors = is_array($decoded) ? ($decoded['errors'] ?? null) : null;
        if (! is_array($errors)) {
            return null;
        }
        foreach ($errors as $error) {
            $code = is_array($error) ? ($error['error_code'] ?? null) : null;
            if (is_string($code) && preg_match('/^[A-Z0-9]{11}$/D', $code)) {
                return $code;
            }
        }

        return null;
    }

    private function baseUrl(): string
    {
        return (string) config('v2_fincode.base_url');
    }

    private function secret(#[SensitiveParameter] string $secret): string
    {
        return $secret;
    }
}
