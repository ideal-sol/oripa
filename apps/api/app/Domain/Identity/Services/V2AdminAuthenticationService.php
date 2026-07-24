<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\Admin;
use App\Models\V2\AdminInvitation;
use App\Models\V2\AdminRecoveryCode;
use App\Models\V2\AdminTotpMethod;
use App\Models\V2\AdminWebauthnMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2AdminAuthenticationService
{
    public function __construct(
        private readonly V2PasswordPolicy $passwordPolicy,
        private readonly V2EmailNormalizer $emails,
        private readonly V2SecureToken $tokens,
        private readonly V2AuthTransactionStore $transactions,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2SessionManager $sessions,
        private readonly V2TotpService $totp,
        private readonly V2WebauthnService $webauthn,
        private readonly V2RecoveryCodeService $recoveryCodes,
        private readonly V2MfaPolicy $mfaPolicy,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /**
     * @return array{transaction_token: string, expires_in: int, methods: list<string>, webauthn: array<string, mixed>|null}
     */
    public function login(
        string $email,
        #[SensitiveParameter] string $password,
        string $ip,
        #[SensitiveParameter] ?string $invitationToken = null
    ): array {
        $normalized = $this->emails->normalize($email);
        $this->rateLimiter->assertGlobal('admin_login_ip', $ip);
        $this->rateLimiter->assertAccount('admin_login_failure', $normalized, $ip);
        $admin = Admin::query()->where('email_normalized', $normalized)->first();
        if ($admin === null) {
            $this->rateLimiter->hitAccount('admin_login_failure', $normalized, $ip);
            $this->events->record('login_failure', ['realm' => 'admin']);
            throw $this->invalidCredentials();
        }
        if ($admin->state === V2AdminState::Invited) {
            $admin = $this->consumeInvitation($admin, $invitationToken, $password);
        } elseif ($admin->state !== V2AdminState::Active) {
            $this->rateLimiter->hitAccount('admin_login_failure', $normalized, $ip);
            throw $this->invalidCredentials();
        } elseif (! $this->passwordPolicy->verify($password, $admin->password_hash)) {
            $this->rateLimiter->hitAccount('admin_login_failure', $normalized, $ip);
            $this->events->record('login_failure', ['realm' => 'admin']);
            throw $this->invalidCredentials();
        }
        if ($this->passwordPolicy->needsRehash($admin->password_hash)) {
            $admin->forceFill(['password_hash' => $this->passwordPolicy->hash($password)])->save();
        }

        $methods = $this->availableMethods($admin);
        $transaction = $this->transactions->issue(
            'admin_preauth',
            ['admin_id' => (int) $admin->getKey(), 'methods' => $methods],
            (int) config('v2_identity.transactions.admin_preauth_ttl_seconds')
        );
        $assertion = in_array('webauthn', $methods, true)
            ? $this->webauthn->beginAssertion($admin)
            : null;
        $this->events->record('login_success', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
            'stage' => 'password',
        ]);

        return [
            'transaction_token' => $transaction['token'],
            'expires_in' => $transaction['expires_in'],
            'methods' => $methods,
            'webauthn' => $assertion,
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{
     *   admin: Admin,
     *   session: array{token: string, absolute_expires_at: \DateTimeInterface},
     *   requires_mfa_enrollment: bool,
     *   enrollment_transaction: array{token: string, expires_in: int}|null
     * }
     */
    public function verifyMfa(
        #[SensitiveParameter] string $transactionToken,
        string $method,
        #[SensitiveParameter] ?string $code = null,
        #[SensitiveParameter] ?string $challengeToken = null,
        array $credential = []
    ): array {
        $this->rateLimiter->assertSubject('mfa_verify', $transactionToken);
        $payload = $this->readPreauth($transactionToken);
        $admin = Admin::query()->find((int) ($payload['admin_id'] ?? 0));
        if ($admin === null || ! in_array($admin->state, [
            V2AdminState::Active,
            V2AdminState::Invited,
        ], true)) {
            throw $this->invalidMfa();
        }

        $requiresEnrollment = false;
        $verified = match ($method) {
            'totp' => $this->verifyTotp($admin, $code),
            'webauthn' => is_string($challengeToken)
                && $this->webauthn->verifyAssertion($admin, $challengeToken, $credential),
            'recovery_code' => $this->verifyRecoveryCode($admin, $code, $requiresEnrollment),
            default => false,
        };
        if (! $verified || ! $this->mfaPolicySatisfied($admin)) {
            $this->events->record('mfa_failure', [
                'realm' => 'admin',
                'subject_id' => $admin->public_id,
            ]);
            throw $this->invalidMfa();
        }

        $this->transactions->forget($transactionToken);
        $session = $this->sessions->issue(
            V2Realm::Admin,
            (int) $admin->getKey(),
            true,
            $requiresEnrollment
        );
        $enrollmentTransaction = $requiresEnrollment
            ? $this->transactions->issue(
                'admin_recovery_enrollment',
                ['admin_id' => (int) $admin->getKey()],
                (int) config('v2_identity.transactions.admin_preauth_ttl_seconds')
            )
            : null;
        $this->events->record('mfa_success', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
            'method' => $method,
        ]);

        return [
            'admin' => $admin,
            'session' => $session,
            'requires_mfa_enrollment' => $requiresEnrollment,
            'enrollment_transaction' => $enrollmentTransaction,
        ];
    }

    public function adminForEnrollment(#[SensitiveParameter] string $transactionToken): Admin
    {
        $purpose = 'admin_preauth';
        try {
            $payload = $this->transactions->read($transactionToken, $purpose);
        } catch (\Throwable) {
            $purpose = 'admin_recovery_enrollment';
            try {
                $payload = $this->transactions->read($transactionToken, $purpose);
            } catch (\Throwable) {
                throw $this->invalidEnrollmentTransaction();
            }
        }
        $admin = Admin::query()->find((int) ($payload['admin_id'] ?? 0));
        if (
            $admin === null
            || ($purpose === 'admin_preauth' && $admin->state !== V2AdminState::Invited)
            || ($purpose === 'admin_recovery_enrollment'
                && $admin->state !== V2AdminState::Active)
        ) {
            throw $this->invalidEnrollmentTransaction();
        }

        return $admin;
    }

    public function beginTotp(
        #[SensitiveParameter] string $transactionToken
    ): array {
        return $this->totp->begin(
            $this->adminForEnrollment($transactionToken),
            $this->transactions
        );
    }

    public function confirmTotp(
        #[SensitiveParameter] string $transactionToken,
        #[SensitiveParameter] string $enrollmentToken,
        #[SensitiveParameter] string $code
    ): void {
        $admin = $this->adminForEnrollment($transactionToken);
        $this->totp->confirm($admin, $this->transactions, $enrollmentToken, $code);
        $this->activateIfPolicySatisfied($admin);
        $this->events->record('mfa_enrollment', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
            'method' => 'totp',
        ]);
    }

    public function beginWebauthn(
        #[SensitiveParameter] string $transactionToken,
        string $label
    ): array {
        return $this->webauthn->beginRegistration(
            $this->adminForEnrollment($transactionToken),
            $label
        );
    }

    /**
     * @param array<string, mixed> $credential
     */
    public function completeWebauthn(
        #[SensitiveParameter] string $transactionToken,
        #[SensitiveParameter] string $challengeToken,
        array $credential
    ): void {
        $admin = $this->adminForEnrollment($transactionToken);
        $this->webauthn->completeRegistration($admin, $challengeToken, $credential);
        $this->activateIfPolicySatisfied($admin);
        $this->events->record('mfa_enrollment', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
            'method' => 'webauthn',
        ]);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Admin $admin): array
    {
        if (! $this->mfaPolicySatisfied($admin)) {
            throw $this->invalidMfa();
        }
        $codes = $this->recoveryCodes->regenerate($admin);
        $this->events->record('mfa_enrollment', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
            'method' => 'recovery_codes',
        ]);

        return $codes;
    }

    public function logout(Request $request): void
    {
        $this->sessions->revoke($request, V2Realm::Admin);
        $this->events->record('logout', ['realm' => 'admin']);
    }

    private function consumeInvitation(
        Admin $admin,
        #[SensitiveParameter] ?string $invitationToken,
        #[SensitiveParameter] string $password
    ): Admin {
        if (! is_string($invitationToken)) {
            throw $this->invalidCredentials();
        }

        try {
            $passwordHash = $this->passwordPolicy->hash($password);
        } catch (\InvalidArgumentException) {
            throw $this->invalidCredentials();
        }

        return DB::transaction(function () use ($admin, $invitationToken, $passwordHash): Admin {
            $lockedAdmin = Admin::query()->lockForUpdate()->find($admin->getKey());
            if ($lockedAdmin === null || $lockedAdmin->state !== V2AdminState::Invited) {
                throw $this->invalidCredentials();
            }

            $invitation = AdminInvitation::query()
                ->where('admin_id', $lockedAdmin->getKey())
                ->where('token_hash', $this->tokens->hash($invitationToken))
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if ($invitation === null) {
                throw $this->invalidCredentials();
            }

            $lockedAdmin->forceFill(['password_hash' => $passwordHash])->save();
            $invitation->forceFill(['used_at' => now()])->save();

            return $lockedAdmin;
        });
    }

    /**
     * @return list<string>
     */
    private function availableMethods(Admin $admin): array
    {
        $methods = [];
        if (AdminTotpMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNotNull('confirmed_at')
            ->whereNull('revoked_at')
            ->exists()
        ) {
            $methods[] = 'totp';
        }
        if (AdminWebauthnMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNull('revoked_at')
            ->exists()
        ) {
            $methods[] = 'webauthn';
        }
        if (
            $admin->state === V2AdminState::Active
            && AdminRecoveryCode::query()
                ->where('admin_id', $admin->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->exists()
        ) {
            $methods[] = 'recovery_code';
        }

        return $methods;
    }

    private function verifyTotp(Admin $admin, #[SensitiveParameter] ?string $code): bool
    {
        if (! is_string($code)) {
            return false;
        }
        $methods = AdminTotpMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNotNull('confirmed_at')
            ->whereNull('revoked_at')
            ->get();
        foreach ($methods as $method) {
            if ($this->totp->verify($method, $code)) {
                return true;
            }
        }

        return false;
    }

    private function verifyRecoveryCode(
        Admin $admin,
        #[SensitiveParameter] ?string $code,
        bool &$requiresEnrollment
    ): bool {
        if (! is_string($code) || ! $this->recoveryCodes->consume($admin, $code)) {
            return false;
        }
        $requiresEnrollment = true;
        $this->events->record('recovery_code_use', [
            'realm' => 'admin',
            'subject_id' => $admin->public_id,
        ]);

        return true;
    }

    private function activateIfPolicySatisfied(Admin $admin): void
    {
        if ($admin->state === V2AdminState::Invited && $this->mfaPolicySatisfied($admin)) {
            $admin->forceFill([
                'state' => V2AdminState::Active,
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ])->save();
        }
    }

    private function mfaPolicySatisfied(Admin $admin): bool
    {
        return $this->mfaPolicy->allowsAccess(
            $admin->role,
            AdminWebauthnMethod::query()
                ->where('admin_id', $admin->getKey())
                ->whereNull('revoked_at')
                ->count(),
            AdminTotpMethod::query()
                ->where('admin_id', $admin->getKey())
                ->whereNotNull('confirmed_at')
                ->whereNull('revoked_at')
                ->count()
        );
    }

    private function invalidCredentials(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_CREDENTIALS',
            401,
            'The credentials could not be verified.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readPreauth(#[SensitiveParameter] string $token): array
    {
        try {
            return $this->transactions->read($token, 'admin_preauth');
        } catch (\Throwable) {
            throw new V2AuthenticationException(
                'INVALID_AUTH_TRANSACTION',
                401,
                'The authentication transaction is invalid or expired.'
            );
        }
    }

    private function invalidMfa(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_MFA_CODE',
            401,
            'The MFA verification could not be completed.'
        );
    }

    private function invalidEnrollmentTransaction(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_AUTH_TRANSACTION',
            401,
            'The authentication transaction is invalid or expired.'
        );
    }
}
