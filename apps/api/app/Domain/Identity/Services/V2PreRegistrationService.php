<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class V2PreRegistrationService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2IdentityCorrelation $correlation,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function setting(bool $lock = false): object
    {
        $query = DB::table('pre_registration_settings')->where('id', 1);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function assertAccepting(): CarbonImmutable
    {
        $setting = $this->setting(true);
        $now = CarbonImmutable::now()->startOfSecond();
        if (! $setting->is_enabled || $setting->cutoff_at === null
            || ! $now->lessThan(CarbonImmutable::parse($setting->cutoff_at))) {
            throw new V2AuthenticationException('PRE_REGISTRATION_CLOSED', 409);
        }
        return $now;
    }

    public function suppressWelcome(User $user): bool
    {
        $cutoff = $this->setting()->cutoff_at;
        return $user->pre_registered_at !== null && $cutoff !== null
            && CarbonImmutable::now()->lessThan(CarbonImmutable::parse($cutoff));
    }

    public function campaign(): array
    {
        $setting = $this->setting();
        return [
            'is_enabled' => (bool) $setting->is_enabled,
            'cutoff_at' => $this->timestamp($setting->cutoff_at),
            'point_amount' => (int) ($setting->snapshot_amount ?? $setting->point_amount),
            'point_type' => 'free',
            'status' => $setting->cutoff_at !== null && now()->gte(CarbonImmutable::parse($setting->cutoff_at))
                ? 'closed' : ($setting->is_enabled ? 'accepting' : 'not_started'),
        ];
    }

    public function status(User $user): array
    {
        $setting = $this->setting();
        $grant = DB::table('pre_registration_grants')->where('user_id', $user->id)->first();
        $phone = UserPhoneNumber::query()->where('user_id', $user->id)->first();
        $reason = $this->reason($user, $setting, $phone, $grant === null);
        $target = DB::table('pre_registration_targets')->where('user_id', $user->id)->first();
        if ($grant === null && $target?->status === 'excluded') {
            $reason = $target->reason;
        }
        $status = match (true) {
            $grant !== null => 'activated',
            $user->pre_registered_at === null => 'not_applicant',
            $reason !== null => 'not_eligible',
            $user->email_verified_at === null => 'email_pending',
            $user->first_sms_verified_at === null => 'sms_pending',
            now()->lt(CarbonImmutable::parse($setting->cutoff_at)) => 'pre_registered',
            default => 'awaiting_activation',
        };
        return [
            'status' => $status,
            'reason' => $grant === null ? $reason : null,
            'pre_registered_at' => $this->timestamp($user->pre_registered_at),
            'email_verified' => $user->email_verified_at !== null,
            'sms_verified' => $phone?->verified_at !== null && $phone?->revoked_at === null,
            'registration_completed' => in_array($status, ['pre_registered', 'awaiting_activation', 'activated'], true),
            'activated_at' => $this->timestamp($grant?->activated_at),
            'point_amount' => $grant === null ? null : (int) $grant->point_amount,
            'granted_at' => $this->timestamp($grant?->activated_at),
            'expires_at' => $this->timestamp($grant?->expires_at),
        ];
    }

    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadPreRegistrationSettings);
        return $this->overview();
    }

    public function userDetail(V2AdminAuthorizationContext $context, string $publicId): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadPreRegistrationSettings);
        $user = User::query()->where('public_id', $publicId)->firstOrFail();
        $status = $this->status($user);
        return [
            'pre_registered_at' => $status['pre_registered_at'],
            'created_at' => $this->timestamp($user->created_at),
            'email_verified' => $status['email_verified'],
            'sms_verified' => $status['sms_verified'],
            'activated_at' => $status['activated_at'],
            'point_amount' => $status['point_amount'],
            'expires_at' => $status['expires_at'],
        ];
    }

    public function preview(V2AdminAuthorizationContext $context, array $input): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadPreRegistrationSettings);
        $payload = $this->validate($input);
        $setting = $this->setting();
        $this->revision($setting, $payload['expected_revision']);
        $affected = 0;
        if ($setting->cutoff_at !== null && $payload['cutoff_at'] !== null
            && CarbonImmutable::parse($payload['cutoff_at'])->lt(CarbonImmutable::parse($setting->cutoff_at))) {
            $affected = DB::table('users')->whereNotNull('pre_registered_at')
                ->where('pre_registered_at', '<', $setting->cutoff_at)
                ->where(function (Builder $query) use ($payload): void {
                    foreach (['pre_registered_at', 'email_verified_at', 'first_sms_verified_at'] as $column) {
                        $query->orWhere($column, '>=', $payload['cutoff_at']);
                    }
                })->count();
        }
        return ['affected_applicants' => $affected];
    }

    public function update(V2AdminAuthorizationContext $context, string $key, array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManagePreRegistrationSettings);
        $payload = $this->validate($input);
        $this->validateKey($key);
        try {
            return DB::transaction(function () use ($context, $admin, $key, $payload): array {
                $claim = $this->idempotency->claim('pre_registration.settings', 'admin', $admin->public_id, $key, $payload);
                if ($claim->replay) {
                    return [...$claim->record->response_data, 'idempotent_replay' => true];
                }
                $setting = $this->setting(true);
                $this->revision($setting, $payload['expected_revision']);
                if ($setting->started_at !== null && $this->timestamp($setting->cutoff_at) !== $this->timestamp($payload['cutoff_at'])) {
                    throw new V2AuthenticationException('PRE_REGISTRATION_CUTOFF_LOCKED', 409);
                }
                if ($setting->started_at !== null && $setting->completed_at === null
                    && (int) $setting->point_amount !== $payload['point_amount']) {
                    throw new V2AuthenticationException('PRE_REGISTRATION_AMOUNT_LOCKED', 409);
                }
                DB::table('pre_registration_settings')->where('id', 1)->update([
                    'is_enabled' => $payload['is_enabled'], 'cutoff_at' => $payload['cutoff_at'],
                    'point_amount' => $payload['point_amount'], 'revision' => $setting->revision + 1,
                    'updated_at' => V2DatabaseTimestamp::format(now()),
                ]);
                $data = $this->overview();
                $this->auditAction($context, 'settings.updated', $setting->public_id, [
                    'before' => ['is_enabled' => $setting->is_enabled, 'cutoff_at' => $setting->cutoff_at,
                        'point_amount' => $setting->point_amount, 'revision' => $setting->revision],
                    'after' => $payload,
                ]);
                $this->idempotency->complete($claim->record, 'pre_registration_setting', $setting->public_id, ['data' => $data]);
                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2AuthenticationException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED' ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS', 409
            );
        }
    }

    public function activate(V2AdminAuthorizationContext $context, string $key, array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManagePreRegistrationSettings);
        $this->validateKey($key);
        if (array_keys($input) !== ['expected_revision'] || ! is_int($input['expected_revision']) || $input['expected_revision'] < 1) {
            throw new V2AuthenticationException('PRE_REGISTRATION_INVALID', 422);
        }
        $lock = DB::selectOne("SELECT pg_try_advisory_lock(hashtextextended('v2.pre_registration.activation', 0)) AS acquired");
        if (! $lock->acquired) {
            throw new V2AuthenticationException('IDEMPOTENCY_REQUEST_IN_PROGRESS', 409, retryable: true, retryAfterSeconds: 1);
        }
        try {
            $batch = DB::transaction(function () use ($context, $admin, $key, $input): object {
                $setting = $this->setting(true);
                $existing = DB::table('pre_registration_batches')->where('admin_id', $admin->id)->where('key_hash', hash('sha256', $key))->first();
                if ($existing !== null) {
                    if ((int) $existing->expected_revision !== $input['expected_revision']) {
                        throw new V2AuthenticationException('IDEMPOTENCY_KEY_REUSED', 409);
                    }
                    return $existing;
                }
                $this->revision($setting, $input['expected_revision']);
                if ($setting->cutoff_at === null || now()->lt(CarbonImmutable::parse($setting->cutoff_at))) {
                    throw new V2AuthenticationException('PRE_REGISTRATION_NOT_OPEN', 409);
                }
                if ($setting->started_at === null) {
                    DB::table('pre_registration_settings')->where('id', 1)->update([
                        'snapshot_amount' => $setting->point_amount, 'snapshot_revision' => $setting->revision,
                        'started_at' => V2DatabaseTimestamp::format(now()),
                    ]);
                    DB::table('pre_registration_targets')->insertUsing(['user_id'], $this->eligibleUsers($setting)->select('users.id'));
                    $this->auditAction($context, 'activation.started', $setting->public_id, ['metadata' => [
                        'point_amount' => (int) $setting->point_amount, 'revision' => (int) $setting->revision,
                        'target_count' => DB::table('pre_registration_targets')->count(),
                    ]]);
                }
                $ids = DB::table('pre_registration_targets')->whereIn('status', ['pending', 'failed'])
                    ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderBy('user_id')->limit(100)->pluck('user_id')->all();
                $id = DB::table('pre_registration_batches')->insertGetId([
                    'public_id' => (string) Str::uuid7(), 'admin_id' => $admin->id, 'key_hash' => hash('sha256', $key),
                    'expected_revision' => $input['expected_revision'], 'user_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                ]);
                return DB::table('pre_registration_batches')->where('id', $id)->firstOrFail();
            }, 3);
            if ($batch->response !== null) {
                return [...json_decode($batch->response, true, flags: JSON_THROW_ON_ERROR), 'idempotent_replay' => true];
            }
            $ids = json_decode($batch->user_ids, true, flags: JSON_THROW_ON_ERROR);
            foreach ($ids as $userId) {
                $this->activateUser($context, (int) $userId);
            }
            return DB::transaction(function () use ($context, $batch, $ids): array {
                $setting = $this->setting(true);
                if ($setting->completed_at === null && ! DB::table('pre_registration_targets')->whereIn('status', ['pending', 'failed'])->exists()) {
                    DB::table('pre_registration_settings')->where('id', 1)->update(['completed_at' => V2DatabaseTimestamp::format(now())]);
                }
                $data = $this->overview($ids);
                $response = ['data' => $data, 'idempotent_replay' => false];
                DB::table('pre_registration_batches')->where('id', $batch->id)->update([
                    'response' => json_encode($response, JSON_THROW_ON_ERROR), 'completed_at' => V2DatabaseTimestamp::format(now()),
                ]);
                $this->auditAction($context, 'batch.completed', $batch->public_id, ['metadata' => array_intersect_key(
                    $data['summary'], array_flip(['pending', 'activated', 'failed', 'excluded', 'granted_points'])
                )]);
                return $response;
            }, 3);
        } finally {
            DB::select("SELECT pg_advisory_unlock(hashtextextended('v2.pre_registration.activation', 0))");
        }
    }

    private function activateUser(V2AdminAuthorizationContext $context, int $userId): void
    {
        try {
            DB::transaction(function () use ($context, $userId): void {
                app(\App\Domain\Point\Services\V2FinancialAdmission::class)->enter();
                $this->authorization->authorizePermission($context, V2Permission::ManagePreRegistrationSettings);
                $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $target = DB::table('pre_registration_targets')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
                if (in_array($target->status, ['activated', 'excluded'], true)) {
                    return;
                }
                $phone = UserPhoneNumber::query()->where('user_id', $userId)->first();
                $hashes = $phone === null ? [] : $this->correlation->hashes(Crypt::decryptString($phone->phone_ciphertext));
                $activeHash = $hashes[0] ?? null;
                sort($hashes, SORT_STRING);
                foreach ($hashes as $hash) {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$hash]);
                }
                $setting = $this->setting();
                $reason = $this->reason($user, $setting, $phone, true);
                if ($reason !== null || $user->email_verified_at === null || $user->first_sms_verified_at === null) {
                    $this->targetResult($userId, 'excluded', $reason ?? 'qualification_unavailable');
                    $this->auditAction($context, 'user.excluded', $user->public_id, ['reason_code' => $reason ?? 'qualification_unavailable']);
                    return;
                }
                $occurred = CarbonImmutable::now()->startOfSecond();
                $user->forceFill(['created_at' => $occurred])->save();
                $operation = $this->points->grantPreRegistrationReward($userId, (int) $setting->snapshot_amount, $occurred, $context->adminId);
                DB::table('pre_registration_grants')->insert([
                    'public_id' => (string) Str::uuid7(), 'user_id' => $userId, 'phone_hmac' => $activeHash,
                    'point_operation_id' => $operation->id, 'point_amount' => $setting->snapshot_amount,
                    'setting_revision' => $setting->snapshot_revision, 'activated_at' => V2DatabaseTimestamp::format($occurred),
                    'expires_at' => V2DatabaseTimestamp::format($occurred->addDays(180)),
                ]);
                $this->targetResult($userId, 'activated', null);
                $this->auditAction($context, 'user.activated', $user->public_id, ['metadata' => [
                    'point_amount' => (int) $setting->snapshot_amount, 'setting_revision' => (int) $setting->snapshot_revision,
                    'operation_public_id' => $operation->public_id,
                ]]);
            }, 3);
        } catch (V2AuthenticationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            DB::transaction(function () use ($context, $userId): void {
                $this->targetResult($userId, 'failed', 'temporary_failure');
                $publicId = User::query()->whereKey($userId)->value('public_id');
                $this->auditAction($context, 'user.failed', $publicId, ['outcome' => 'failure', 'reason_code' => 'temporary_failure']);
            }, 3);
        }
    }

    private function reason(User $user, object $setting, ?UserPhoneNumber $phone, bool $checkReward): ?string
    {
        if ($phone !== null && app(V2PhoneBenefitEligibility::class)->reused((int) $user->id)) return 'phone_already_rewarded';
        if ($user->pre_registered_at === null) return 'not_pre_registration_entry';
        if ($setting->cutoff_at === null) return 'qualification_unavailable';
        $cutoff = CarbonImmutable::parse($setting->cutoff_at);
        foreach (['pre_registered_at' => 'registration_after_cutoff', 'email_verified_at' => 'email_after_cutoff', 'first_sms_verified_at' => 'sms_after_cutoff'] as $column => $reason) {
            if ($user->{$column} !== null && $user->{$column}->gte($cutoff)) return $reason;
        }
        if (in_array($user->state, [V2UserState::Closed, V2UserState::Anonymized, V2UserState::Suspended], true)) return 'account_unavailable';
        if ($user->email_verified_at === null) return now()->gte($cutoff) ? 'email_after_cutoff' : null;
        if ($user->first_sms_verified_at === null) return now()->gte($cutoff) ? 'sms_after_cutoff' : null;
        if ($phone === null || $phone->verified_at === null || $phone->revoked_at !== null) return 'phone_unavailable';
        if ($checkReward && DB::table('pre_registration_grants')->whereIn('phone_hmac', $this->correlation->hashes(Crypt::decryptString($phone->phone_ciphertext)))->exists()) return 'phone_already_rewarded';
        return null;
    }

    private function eligibleUsers(object $setting): Builder
    {
        if ($setting->cutoff_at === null) {
            return DB::table('users')->whereRaw('false');
        }
        return DB::table('users')->whereNotNull('pre_registered_at')
            ->where('pre_registered_at', '<', $setting->cutoff_at)
            ->where('email_verified_at', '<', $setting->cutoff_at)
            ->where('first_sms_verified_at', '<', $setting->cutoff_at)
            ->whereIn('state', ['active', 'restricted']);
    }

    private function overview(array $ids = []): array
    {
        $setting = $this->setting();
        $applicants = DB::table('users')->whereNotNull('pre_registered_at');
        $counts = DB::table('pre_registration_targets')->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');
        $eligible = $setting->started_at === null ? $this->eligibleUsers($setting)->count() : DB::table('pre_registration_targets')->count();
        $results = DB::table('pre_registration_targets as target')->join('users', 'users.id', '=', 'target.user_id');
        if ($ids !== []) $results->whereIn('target.user_id', $ids);
        else $results->whereIn('target.status', ['failed', 'excluded']);
        return [
            'is_enabled' => (bool) $setting->is_enabled, 'cutoff_at' => $this->timestamp($setting->cutoff_at),
            'point_amount' => (int) $setting->point_amount, 'revision' => (int) $setting->revision,
            'snapshot_amount' => $setting->snapshot_amount === null ? null : (int) $setting->snapshot_amount,
            'started_at' => $this->timestamp($setting->started_at), 'completed_at' => $this->timestamp($setting->completed_at),
            'summary' => [
                'applicants' => (clone $applicants)->count(),
                'email_pending' => (clone $applicants)->whereNull('email_verified_at')->count(),
                'sms_pending' => (clone $applicants)->whereNotNull('email_verified_at')->whereNull('first_sms_verified_at')->count(),
                'eligible' => $eligible, 'pending' => $setting->started_at === null ? $eligible : (int) ($counts['pending'] ?? 0),
                'activated' => (int) ($counts['activated'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
                'excluded' => (clone $applicants)->count() - $eligible + (int) ($counts['excluded'] ?? 0),
                'granted_points' => (int) DB::table('pre_registration_grants')->sum('point_amount'),
            ],
            'results' => $results->orderBy('target.user_id')->limit(100)->get(['users.public_id as user_id', 'target.status', 'target.reason'])->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function targetResult(int $userId, string $status, ?string $reason): void
    {
        DB::table('pre_registration_targets')->where('user_id', $userId)->update([
            'status' => $status, 'reason' => $reason, 'attempts' => DB::raw('attempts + 1'),
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }

    private function validate(array $input): array
    {
        if (count($input) !== 4 || ! is_int($input['expected_revision'] ?? null)
            || ! is_bool($input['is_enabled'] ?? null) || ! is_int($input['point_amount'] ?? null)
            || ! array_key_exists('cutoff_at', $input)
            || Validator::make($input, [
                'expected_revision' => 'required|integer|min:1', 'is_enabled' => 'required|boolean',
                'point_amount' => 'required|integer|between:1,1000000',
                'cutoff_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            ])->fails() || ($input['is_enabled'] && $input['cutoff_at'] === null)) {
            throw new V2AuthenticationException('PRE_REGISTRATION_INVALID', 422);
        }
        $input['cutoff_at'] = $this->timestamp($input['cutoff_at']);
        return $input;
    }

    private function validateKey(string $key): void
    {
        if (! preg_match('/\A[\x21-\x7e]{16,128}\z/', $key)) {
            throw new V2AuthenticationException('IDEMPOTENCY_KEY_REQUIRED', 422);
        }
    }

    private function revision(object $setting, int $expected): void
    {
        if ((int) $setting->revision !== $expected) throw new V2AuthenticationException('PRE_REGISTRATION_REVISION_CONFLICT', 409);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
    }

    private function auditAction(V2AdminAuthorizationContext $context, string $action, string $target, array $attributes = []): void
    {
        $this->audit->record('pre_registration.'.$action, [
            'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value, 'auth_realm' => 'admin', 'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'pre_registration', 'target_public_id' => $target, ...$attributes,
        ]);
    }
}
