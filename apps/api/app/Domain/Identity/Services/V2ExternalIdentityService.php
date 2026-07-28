<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\ExternalIdentityAccountHistory;
use App\Models\V2\ExternalIdentityTransaction;
use App\Models\V2\User;
use App\Models\V2\UserSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2ExternalIdentityService
{
    private const PROVIDER = 'google';
    private const ISSUER = 'https://accounts.google.com';
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function __construct(
        private readonly V2GoogleOidcTransport $transport,
        private readonly V2GoogleIdTokenVerifier $idTokens,
        private readonly V2IdentityCorrelation $correlation,
        private readonly V2SecureToken $tokens,
        private readonly V2PasswordPolicy $passwords,
        private readonly V2SessionManager $sessions,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2OutboxService $outbox,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /**
     * @return array{authorization_url: string, binding_token: string, expires_at: \DateTimeInterface}
     */
    public function start(
        string $purpose,
        string $returnPath,
        string $ip,
        string $requestId,
        ?User $user,
        Request $request
    ): array {
        if (! in_array($purpose, ['login', 'link', 'reauthentication'], true)) {
            throw $this->invalidRequest();
        }
        if ($purpose !== 'login' && $user === null) {
            throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
        }
        $this->assertConfiguration();
        $this->assertReturnPath($returnPath);
        try {
            if ($purpose === 'login') {
                $this->rateLimiter->assertGlobal('oidc_login_start', $ip);
            } else {
                $this->rateLimiter->assertSubject(
                    'oidc_link_start',
                    $user->public_id.'|'.$purpose
                );
            }
        } catch (V2AuthenticationException $exception) {
            $this->events->record('external_identity_rate_limited', [
                'realm' => $user === null ? 'system' : 'user',
                'subject_id' => $user?->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        $sessionHash = null;
        if ($purpose !== 'login') {
            try {
                $session = $this->sessions->requireActiveUserSession(
                    $request,
                    (int) $user?->getKey()
                );
            } catch (RuntimeException) {
                throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
            }
            $sessionHash = (string) $session->getKey();
        }

        $state = $this->tokens->generate();
        $nonce = $this->tokens->generate();
        $verifier = $this->pkceVerifier();
        $binding = $this->tokens->generate();
        $redirectUri = $this->configuration('redirect_uri');
        $expiresAt = now()->startOfSecond()->addMinutes(
            (int) config('v2_identity.external_identity.transaction_ttl_minutes', 10)
        );
        ExternalIdentityTransaction::query()->create([
            'provider' => self::PROVIDER,
            'purpose' => $purpose,
            'state_hash' => $this->tokens->hash($state),
            'nonce_hash' => $this->tokens->hash($nonce),
            'code_verifier_ciphertext' => Crypt::encryptString($verifier),
            'browser_binding_hash' => $this->tokens->hash($binding),
            'user_id' => $user?->getKey(),
            'user_session_hash' => $sessionHash,
            'return_path' => $returnPath,
            'redirect_uri' => $redirectUri,
            'request_id' => $this->requestId($requestId),
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);
        $this->events->record('oidc_start', [
            'realm' => $user === null ? 'system' : 'user',
            'subject_id' => $user?->public_id,
            'method' => self::PROVIDER,
            'stage' => $purpose,
        ]);

        return [
            'authorization_url' => self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
                'client_id' => $this->configuration('client_id'),
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email',
                'state' => $state,
                'nonce' => $nonce,
                'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
                'code_challenge_method' => 'S256',
                'prompt' => 'select_account',
            ], '', '&', PHP_QUERY_RFC3986),
            'binding_token' => $binding,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{
     *   purpose: string,
     *   return_path: string,
     *   user: User,
     *   session: array{token: string, absolute_expires_at: \DateTimeInterface}
     * }
     */
    public function callback(
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $bindingToken,
        string $callbackUrl,
        string $ip,
        Request $request
    ): array {
        $stateHash = $this->tokens->hash($state);
        $claimedTransactionId = null;
        try {
            $this->rateLimiter->assertAccount('oidc_callback_failure', $stateHash, $ip);
            $claimed = $this->claimTransaction(
                $stateHash,
                $bindingToken,
                $callbackUrl,
                $request
            );
            $claimedTransactionId = $claimed['transaction']->getKey();
            $idToken = $this->transport->exchangeAuthorizationCode(
                $authorizationCode,
                $claimed['verifier'],
                $claimed['transaction']->redirect_uri
            );
            $identity = $this->idTokens->verify(
                $idToken,
                $claimed['transaction']->nonce_hash
            );

            return $this->complete($claimedTransactionId, $identity, $request);
        } catch (V2OidcProtocolException $exception) {
            if ($claimedTransactionId === null) {
                $this->expirePendingTransaction($stateHash);
            }
            $this->failTransaction($claimedTransactionId);
            $this->rateLimiter->hitAccount('oidc_callback_failure', $stateHash, $ip);
            $this->events->record($this->protocolEvent($exception->reasonCode), [
                'realm' => 'system',
                'method' => self::PROVIDER,
                'reason' => $exception->reasonCode,
            ]);
            throw $this->invalidExternalIdentity();
        } catch (V2AuthenticationException $exception) {
            $this->failTransaction($claimedTransactionId);
            if ($exception->status !== 429) {
                $this->rateLimiter->hitAccount('oidc_callback_failure', $stateHash, $ip);
            }
            throw $exception;
        } catch (QueryException) {
            $this->failTransaction($claimedTransactionId);
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_CONFLICT',
                409,
                'The external identity request conflicts with an existing account.'
            );
        } catch (Throwable) {
            $this->failTransaction($claimedTransactionId);
            $this->events->record('oidc_provider_failure', [
                'realm' => 'system',
                'method' => self::PROVIDER,
                'reason' => 'processing_failed',
            ]);
            throw $this->invalidExternalIdentity();
        }
    }

    /**
     * @return Collection<int, array{id: string, provider: string, linked_at: string, last_authenticated_at: ?string}>
     */
    public function identities(User $user): Collection
    {
        return ExternalIdentityAccount::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ExternalIdentityAccount $account): array => [
                'id' => $account->public_id,
                'provider' => $account->provider,
                'linked_at' => $account->linked_at->toIso8601String(),
                'last_authenticated_at' => $account->last_authenticated_at?->toIso8601String(),
            ]);
    }

    /**
     * @return array{token: string, absolute_expires_at: \DateTimeInterface}
     */
    public function reauthenticatePassword(
        User $user,
        Request $request,
        #[SensitiveParameter] string $password
    ): array {
        $sessionHash = $this->sessions->sessionIdHash($request, V2Realm::User);
        if ($sessionHash === null) {
            throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
        }
        try {
            $this->rateLimiter->assertSubject(
                'user_password_reauthentication',
                $sessionHash
            );
        } catch (V2AuthenticationException $exception) {
            $this->events->record('external_identity_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
        if (
            ! $user->password_login_enabled
            || ! $this->passwords->verify($password, $user->password_hash)
        ) {
            $this->events->record('user_reauthentication_failed', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => 'password',
                'reason' => 'invalid_credential',
            ]);
            throw new V2AuthenticationException(
                'INVALID_REAUTHENTICATION',
                401,
                'The reauthentication request could not be completed.'
            );
        }

        return DB::transaction(function () use ($user, $request): array {
            $session = $this->sessions->requireActiveUserSession(
                $request,
                (int) $user->getKey(),
                true
            );
            $rotated = $this->sessions->rotateLockedUserSession($session);
            $this->events->record('user_reauthentication_succeeded', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => 'password',
            ]);

            return $rotated;
        }, 3);
    }

    /**
     * @return array{token: string, absolute_expires_at: \DateTimeInterface}
     */
    public function unlinkGoogle(User $user, Request $request): array
    {
        try {
            $this->rateLimiter->assertSubject('oidc_unlink', $user->public_id);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('external_identity_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        try {
            return DB::transaction(function () use ($user, $request): array {
                $session = $this->sessions->requireFreshUserSession(
                    $request,
                    (int) $user->getKey(),
                    true,
                    (int) config('v2_identity.external_identity.recent_auth_minutes', 5)
                );
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $account = ExternalIdentityAccount::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->where('provider', self::PROVIDER)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();
                if ($account === null || ! $lockedUser->password_login_enabled) {
                    throw new V2AuthenticationException(
                        'LAST_CREDENTIAL_REQUIRED',
                        409,
                        'The last available sign-in credential cannot be removed.'
                    );
                }

                $now = now()->startOfSecond();
                $account->forceFill(['revoked_at' => $now])->save();
                $this->history($account, 'unlinked', $this->requestId($request->header('X-Request-ID')));
                DB::table('user_remember_devices')
                    ->where('user_id', $lockedUser->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                UserSession::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->whereKeyNot($session->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                $rotated = $this->sessions->rotateLockedUserSession($session);
                $this->outbox->enqueue(
                    'identity.external-identity-unlinked',
                    'user',
                    $lockedUser->public_id,
                    'identity.external_identity.unlinked',
                    ['provider' => self::PROVIDER],
                    'external-unlinked:'.$account->public_id
                );
                $this->events->record('external_unlink_succeeded', [
                    'realm' => 'user',
                    'subject_id' => $lockedUser->public_id,
                    'method' => self::PROVIDER,
                ]);

                return $rotated;
            }, 3);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('external_unlink_rejected', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => self::PROVIDER,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        } catch (RuntimeException) {
            $this->events->record('external_unlink_rejected', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => self::PROVIDER,
                'reason' => 'fresh_authentication_required',
            ]);
            throw new V2AuthenticationException(
                'FRESH_AUTHENTICATION_REQUIRED',
                403,
                'Recent authentication is required.'
            );
        }
    }

    /**
     * @return array{transaction: ExternalIdentityTransaction, verifier: string}
     */
    private function claimTransaction(
        string $stateHash,
        #[SensitiveParameter] string $bindingToken,
        string $callbackUrl,
        Request $request
    ): array {
        return DB::transaction(function () use (
            $stateHash,
            $bindingToken,
            $callbackUrl,
            $request
        ): array {
            $transaction = ExternalIdentityTransaction::query()
                ->where('state_hash', $stateHash)
                ->lockForUpdate()
                ->first();
            if (
                $transaction === null
                || $transaction->status !== 'pending'
                || ! $transaction->expires_at->isFuture()
                || ! hash_equals(
                    $transaction->browser_binding_hash,
                    $this->tokens->hash($bindingToken)
                )
                || ! hash_equals($transaction->redirect_uri, $callbackUrl)
            ) {
                throw new V2OidcProtocolException('state_or_transaction_rejected');
            }
            if ($transaction->purpose !== 'login') {
                $currentHash = $this->sessions->sessionIdHash($request, V2Realm::User);
                if (
                    $currentHash === null
                    || ! hash_equals($transaction->user_session_hash, $currentHash)
                ) {
                    throw new V2OidcProtocolException('session_binding_rejected');
                }
                $active = UserSession::query()
                    ->whereKey($currentHash)
                    ->where('user_id', $transaction->user_id)
                    ->whereNull('revoked_at')
                    ->where('idle_expires_at', '>', now())
                    ->where('absolute_expires_at', '>', now())
                    ->exists();
                if (! $active) {
                    throw new V2OidcProtocolException('session_binding_rejected');
                }
            }
            $transaction->forceFill([
                'status' => 'processing',
                'processing_at' => now()->startOfSecond(),
            ])->save();

            return [
                'transaction' => $transaction,
                'verifier' => Crypt::decryptString($transaction->code_verifier_ciphertext),
            ];
        }, 3);
    }

    /**
     * @return array{
     *   purpose: string,
     *   return_path: string,
     *   user: User,
     *   session: array{token: string, absolute_expires_at: \DateTimeInterface}
     * }
     */
    private function complete(
        int $transactionId,
        V2VerifiedGoogleIdentity $identity,
        Request $request
    ): array {
        return DB::transaction(function () use ($transactionId, $identity, $request): array {
            $transaction = ExternalIdentityTransaction::query()
                ->whereKey($transactionId)
                ->where('status', 'processing')
                ->lockForUpdate()
                ->firstOrFail();
            $subjectHash = $this->correlation->hash(
                self::PROVIDER.'|'.$identity->issuer.'|'.$identity->subject
            );
            $account = ExternalIdentityAccount::query()
                ->where('provider', self::PROVIDER)
                ->where('issuer', self::ISSUER)
                ->where('subject_hash', $subjectHash)
                ->lockForUpdate()
                ->first();

            $result = match ($transaction->purpose) {
                'login' => $this->completeLogin($transaction, $identity, $account),
                'link' => $this->completeLink($transaction, $identity, $account, $request),
                'reauthentication' => $this->completeReauthentication(
                    $transaction,
                    $account,
                    $request
                ),
                default => throw $this->invalidExternalIdentity(),
            };
            $transaction->forceFill([
                'status' => 'completed',
                'used_at' => now()->startOfSecond(),
            ])->save();

            return [
                'purpose' => $transaction->purpose,
                'return_path' => $transaction->return_path,
                ...$result,
            ];
        }, 3);
    }

    /**
     * @return array{user: User, session: array{token: string, absolute_expires_at: \DateTimeInterface}}
     */
    private function completeLogin(
        ExternalIdentityTransaction $transaction,
        V2VerifiedGoogleIdentity $identity,
        ?ExternalIdentityAccount $account
    ): array {
        if ($account !== null && $account->revoked_at === null) {
            $user = User::query()->whereKey($account->user_id)->lockForUpdate()->firstOrFail();
            $this->assertLoginState($user);
            $account->forceFill(['last_authenticated_at' => now()->startOfSecond()])->save();
            $this->history($account, 'authenticated', $transaction->request_id);
            $session = $this->sessions->issue(V2Realm::User, (int) $user->getKey());
            $this->events->record('external_login_succeeded', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => self::PROVIDER,
            ]);

            return compact('user', 'session');
        }
        if ($account !== null) {
            throw $this->invalidExternalIdentity();
        }
        $collision = User::query()
            ->where('email_normalized', $identity->emailNormalized)
            ->whereNotNull('email_verified_at')
            ->lockForUpdate()
            ->exists();
        if ($collision) {
            $this->events->record('external_email_conflict', [
                'realm' => 'system',
                'method' => self::PROVIDER,
                'reason' => 'explicit_link_required',
            ]);
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_LINK_REQUIRED',
                409,
                'Sign in with an existing credential before linking this identity.'
            );
        }

        $user = User::query()->create([
            'email_display' => $identity->emailDisplay,
            'email_normalized' => $identity->emailNormalized,
            'email_verified_at' => now()->startOfSecond(),
            'password_hash' => $this->passwords->hash($this->tokens->generate()),
            'password_login_enabled' => false,
            'state' => V2UserState::Active,
        ]);
        $account = ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => self::PROVIDER,
            'issuer' => self::ISSUER,
            'subject_hash' => $this->correlation->hash(
                self::PROVIDER.'|'.$identity->issuer.'|'.$identity->subject
            ),
            'linked_at' => now()->startOfSecond(),
            'last_authenticated_at' => now()->startOfSecond(),
        ]);
        $this->history($account, 'linked', $transaction->request_id);
        $session = $this->sessions->issue(V2Realm::User, (int) $user->getKey());
        $this->outbox->enqueue(
            'identity.external-user-created',
            'user',
            $user->public_id,
            'identity.external_user.created',
            ['provider' => self::PROVIDER],
            'external-user-created:'.$account->public_id
        );
        $this->events->record('external_user_created', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'method' => self::PROVIDER,
        ]);

        return compact('user', 'session');
    }

    /**
     * @return array{user: User, session: array{token: string, absolute_expires_at: \DateTimeInterface}}
     */
    private function completeLink(
        ExternalIdentityTransaction $transaction,
        V2VerifiedGoogleIdentity $identity,
        ?ExternalIdentityAccount $account,
        Request $request
    ): array {
        $user = User::query()->whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();
        $this->assertLoginState($user);
        if (
            $account !== null
            || ExternalIdentityAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', self::PROVIDER)
                ->exists()
        ) {
            $this->events->record('external_link_rejected', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => self::PROVIDER,
                'reason' => 'identity_already_bound',
            ]);
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_CONFLICT',
                409,
                'The external identity cannot be linked.'
            );
        }
        $account = ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => self::PROVIDER,
            'issuer' => self::ISSUER,
            'subject_hash' => $this->correlation->hash(
                self::PROVIDER.'|'.$identity->issuer.'|'.$identity->subject
            ),
            'linked_at' => now()->startOfSecond(),
            'last_authenticated_at' => now()->startOfSecond(),
        ]);
        $this->history($account, 'linked', $transaction->request_id);
        $session = $this->lockedBoundSession($transaction, $request);
        $rotated = $this->sessions->rotateLockedUserSession($session);
        $this->outbox->enqueue(
            'identity.external-identity-linked',
            'user',
            $user->public_id,
            'identity.external_identity.linked',
            ['provider' => self::PROVIDER],
            'external-linked:'.$account->public_id
        );
        $this->events->record('external_link_succeeded', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'method' => self::PROVIDER,
        ]);

        return ['user' => $user, 'session' => $rotated];
    }

    /**
     * @return array{user: User, session: array{token: string, absolute_expires_at: \DateTimeInterface}}
     */
    private function completeReauthentication(
        ExternalIdentityTransaction $transaction,
        ?ExternalIdentityAccount $account,
        Request $request
    ): array {
        if (
            $account === null
            || $account->revoked_at !== null
            || $account->user_id !== $transaction->user_id
        ) {
            throw $this->invalidExternalIdentity();
        }
        $user = User::query()->whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();
        $this->assertLoginState($user);
        $account->forceFill(['last_authenticated_at' => now()->startOfSecond()])->save();
        $this->history($account, 'authenticated', $transaction->request_id);
        $session = $this->lockedBoundSession($transaction, $request);
        $rotated = $this->sessions->rotateLockedUserSession($session);
        $this->events->record('user_reauthentication_succeeded', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'method' => self::PROVIDER,
        ]);

        return ['user' => $user, 'session' => $rotated];
    }

    private function lockedBoundSession(
        ExternalIdentityTransaction $transaction,
        Request $request
    ): UserSession {
        $current = $this->sessions->sessionIdHash($request, V2Realm::User);
        if ($current === null || ! hash_equals($transaction->user_session_hash, $current)) {
            throw $this->invalidExternalIdentity();
        }
        $session = UserSession::query()
            ->whereKey($current)
            ->where('user_id', $transaction->user_id)
            ->whereNull('revoked_at')
            ->where('idle_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now())
            ->lockForUpdate()
            ->first();
        if ($session === null) {
            throw $this->invalidExternalIdentity();
        }

        return $session;
    }

    private function failTransaction(?int $transactionId): void
    {
        if ($transactionId === null) {
            return;
        }
        try {
            DB::transaction(function () use ($transactionId): void {
                $transaction = ExternalIdentityTransaction::query()
                    ->whereKey($transactionId)
                    ->where('status', 'processing')
                    ->lockForUpdate()
                    ->first();
                if ($transaction !== null) {
                    $transaction->forceFill([
                        'status' => 'failed',
                        'used_at' => now()->startOfSecond(),
                    ])->save();
                }
            }, 3);
        } catch (Throwable) {
            // The original authentication error remains authoritative.
        }
    }

    private function expirePendingTransaction(string $stateHash): void
    {
        try {
            DB::transaction(function () use ($stateHash): void {
                $transaction = ExternalIdentityTransaction::query()
                    ->where('state_hash', $stateHash)
                    ->where('status', 'pending')
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->first();
                if ($transaction !== null) {
                    $transaction->forceFill([
                        'status' => 'expired',
                        'used_at' => now()->startOfSecond(),
                    ])->save();
                }
            }, 3);
        } catch (Throwable) {
            // The original protocol error remains authoritative.
        }
    }

    private function history(
        ExternalIdentityAccount $account,
        string $action,
        string $requestId
    ): void {
        ExternalIdentityAccountHistory::query()->create([
            'external_identity_account_id' => $account->getKey(),
            'action' => $action,
            'request_id' => $requestId,
            'occurred_at' => now()->startOfSecond(),
            'created_at' => now()->startOfSecond(),
        ]);
    }

    private function assertLoginState(User $user): void
    {
        if (! in_array($user->state, [V2UserState::Active, V2UserState::Restricted], true)) {
            $this->events->record('external_login_rejected', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'method' => self::PROVIDER,
                'reason' => 'account_state',
            ]);
            throw $this->invalidExternalIdentity();
        }
    }

    private function assertConfiguration(): void
    {
        $this->configuration('client_id');
        $this->configuration('client_secret');
        $redirect = $this->configuration('redirect_uri');
        if (
            parse_url($redirect, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirect, PHP_URL_HOST) === null
            || parse_url($redirect, PHP_URL_USER) !== null
            || parse_url($redirect, PHP_URL_PASS) !== null
        ) {
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_UNAVAILABLE',
                503,
                'External identity authentication is unavailable.',
                true,
                30
            );
        }
    }

    private function configuration(string $key): string
    {
        $value = config('v2_identity.external_identity.google.'.$key);
        if (! is_string($value) || $value === '') {
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_UNAVAILABLE',
                503,
                'External identity authentication is unavailable.',
                true,
                30
            );
        }

        return $value;
    }

    private function assertReturnPath(string $returnPath): void
    {
        $allowlist = config('v2_identity.external_identity.return_path_allowlist', []);
        if (
            ! is_array($allowlist)
            || ! str_starts_with($returnPath, '/')
            || str_starts_with($returnPath, '//')
            || parse_url($returnPath, PHP_URL_HOST) !== null
            || ! in_array($returnPath, $allowlist, true)
        ) {
            throw new V2AuthenticationException(
                'INVALID_REDIRECT',
                422,
                'The return path is not allowed.'
            );
        }
    }

    private function requestId(?string $requestId): string
    {
        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid7();
    }

    private function pkceVerifier(): string
    {
        return $this->base64Url(random_bytes(64));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function protocolEvent(string $reason): string
    {
        return match ($reason) {
            'nonce_rejected' => 'oidc_nonce_rejected',
            'authorization_code_rejected' => 'oidc_pkce_rejected',
            'invalid_signature_or_claims',
            'invalid_identity_claims',
            'unsupported_algorithm',
            'missing_key_id',
            'unknown_key_id',
            'invalid_jwks' => 'oidc_signature_rejected',
            'provider_transport_failed',
            'jwks_unavailable',
            'provider_configuration_unavailable' => 'oidc_provider_failure',
            default => 'oidc_state_rejected',
        };
    }

    private function invalidRequest(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_REQUEST',
            422,
            'The external identity request is invalid.'
        );
    }

    private function invalidExternalIdentity(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            401,
            'The external identity request could not be completed.'
        );
    }
}
