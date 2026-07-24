<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\Admin;
use App\Models\V2\AdminTotpMethod;
use OTPHP\TOTP;
use SensitiveParameter;

final class V2TotpService
{
    /**
     * @return array{enrollment_token: string, secret: string, otpauth_uri: string, expires_in: int}
     */
    public function begin(Admin $admin, V2AuthTransactionStore $transactions): array
    {
        $totp = TOTP::generate();
        $totp->setDigits(6);
        $totp->setPeriod(30);
        $totp->setLabel('admin-'.$admin->public_id);
        $totp->setIssuer((string) config('v2_identity.webauthn.rp_name'));
        $transaction = $transactions->issue(
            'admin_totp_enrollment',
            ['admin_id' => (int) $admin->getKey(), 'secret' => $totp->getSecret()],
            (int) config('v2_identity.transactions.totp_enrollment_ttl_seconds')
        );

        return [
            'enrollment_token' => $transaction['token'],
            'secret' => $totp->getSecret(),
            'otpauth_uri' => $totp->getProvisioningUri(),
            'expires_in' => $transaction['expires_in'],
        ];
    }

    public function confirm(
        Admin $admin,
        V2AuthTransactionStore $transactions,
        #[SensitiveParameter] string $enrollmentToken,
        #[SensitiveParameter] string $code
    ): AdminTotpMethod {
        $payload = $transactions->consume($enrollmentToken, 'admin_totp_enrollment');
        if ((int) ($payload['admin_id'] ?? 0) !== (int) $admin->getKey()) {
            throw $this->invalidCode();
        }
        $secret = $payload['secret'] ?? null;
        if (! is_string($secret) || $this->matchingStep($secret, $code, time()) === null) {
            throw $this->invalidCode();
        }

        return AdminTotpMethod::query()->create([
            'admin_id' => $admin->getKey(),
            'secret_ciphertext' => $secret,
            'encryption_key_version' => 'laravel-app-key-v1',
            'last_used_time_step' => intdiv(time(), 30),
            'confirmed_at' => now(),
        ]);
    }

    public function verify(
        AdminTotpMethod $method,
        #[SensitiveParameter] string $code,
        ?int $timestamp = null
    ): bool {
        $timestamp ??= time();
        $step = $this->matchingStep($method->secret_ciphertext, $code, $timestamp);
        if (
            $step === null
            || ($method->last_used_time_step !== null && $step <= $method->last_used_time_step)
        ) {
            return false;
        }
        $method->forceFill([
            'last_used_time_step' => $step,
            'updated_at' => now(),
        ])->save();

        return true;
    }

    private function matchingStep(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        int $timestamp
    ): ?int {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        foreach ([-1, 0, 1] as $offset) {
            $candidateTimestamp = $timestamp + ($offset * 30);
            if ($candidateTimestamp >= 0 && hash_equals($totp->at($candidateTimestamp), $code)) {
                return intdiv($candidateTimestamp, 30);
            }
        }

        return null;
    }

    private function invalidCode(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_MFA_CODE',
            401,
            'The MFA verification could not be completed.'
        );
    }
}
