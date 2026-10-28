<?php

namespace Tests\Support;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Support\V2DatabaseTimestamp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait MemberRankAccountingFixture
{
    protected function configureRankFixture(): void
    {
        config([
            'cache.default' => 'array', 'v2_identity.transactions.store' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [],
        ]);
        $this->rankClock('2026-10-31T14:59:59Z');
    }

    protected function rankClock(string $instant): void
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    protected function enableRankFixture(string $instant = '2026-10-15T00:00:00Z'): void
    {
        self::assertTrue(app()->environment('testing'));
        DB::table('member_rank_settings')->where('id', 1)->update(['enabled' => true, 'activated_at' => $instant]);
    }

    protected function rankUser(bool $preRegistered = false): User
    {
        $email = 'rank-'.Str::uuid7().'@example.test';
        $origin = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        $user = new User([
            'email_display' => $email, 'email_normalized' => $email,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'),
            'state' => 'active', 'email_verified_at' => $origin->addSecond(),
            'first_registration_qualified_at' => $origin->addSecond(),
            'first_sms_verified_at' => $origin->addSeconds(2),
            'pre_registered_at' => $preRegistered ? $origin : null,
        ]);
        $user->forceFill(['created_at' => $origin])->save();
        if ($preRegistered) {
            $phone = '+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
            UserPhoneNumber::query()->create([
                'user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString($phone),
                'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => $origin->addSeconds(2),
            ]);
        }

        return $user;
    }

    protected function rankPayment(User $user, int $amount, int $free = 0, ?string $providerAt = null, string $method = 'credit_card', ?int $planId = null, bool $commercial = true): array
    {
        $planId ??= DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'code' => 'rank-'.Str::uuid7(), 'version_no' => 1,
            'name' => 'Synthetic rank plan', 'audience_code' => 'all_users', 'amount' => $amount,
            'paid_point_amount' => $amount, 'free_point_amount' => $free, 'currency' => 'JPY',
            'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(V2PaymentService::class);
        if ($commercial) {
            $payment = $this->createCommercialRankPayment($service, $user->id, $planId, 'fincode', 'rank-'.Str::uuid7(), (string) Str::uuid7(), $method);
        } else {
            $endpoint = config('v2_fincode.base_url');
            try {
                config(['v2_fincode.base_url' => 'https://api.test.fincode.jp']);
                $payment = $service->createPayment($user->id, $planId, 'fincode', 'rank-'.Str::uuid7(), (string) Str::uuid7(), $method);
            } finally {
                config(['v2_fincode.base_url' => $endpoint]);
            }
        }
        $event = $service->recordVerifiedProviderEvent('fincode', 'rank-'.Str::uuid7(), 'payment.succeeded', '{}', [],
            $payment->id, null, $providerAt === null ? now() : CarbonImmutable::parse($providerAt));

        return [$payment, $event];
    }

    protected function createCommercialRankPayment(V2PaymentService $service, mixed ...$arguments): object
    {
        // Synthetic commercial business fixture; direct domain creation performs no provider IO.
        // Keep test-mode exclusion tests separate and retain every existing rank assertion.
        $previousEndpoint = config('v2_fincode.base_url');
        try {
            config(['v2_fincode.base_url' => 'https://api.fincode.jp']);
            return $service->createPayment(...$arguments);
        } finally {
            config(['v2_fincode.base_url' => $previousEndpoint]);
        }
    }

    protected function settleRankPayment(User $user, int $amount, int $free = 0, ?string $providerAt = null, bool $commercial = true): object
    {
        [$payment, $event] = $this->rankPayment($user, $amount, $free, $providerAt, commercial: $commercial);
        app(V2PaymentService::class)->confirmSucceeded($event->id);

        return $payment;
    }

    protected function rankAdmin(): V2AdminAuthorizationContext
    {
        $email = 'rank-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'),
            'role' => V2AdminRole::Owner, 'state' => 'active',
        ]);
        $policy = app(V2SessionPolicy::class);
        $hash = $policy->hashSessionId($policy->issueOpaqueSessionId());
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $hash, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(8),
        ]));

        return new V2AdminAuthorizationContext($admin->id, $admin->public_id, V2AdminRole::Owner, $hash, hash('sha256', $hash), (string) Str::uuid7());
    }

    protected function seedRankState(User $user, int $level): void
    {
        DB::table('member_rank_states')->insert([
            'user_id' => $user->id, 'level' => $level, 'baseline_month' => '2026-10-01', 'baseline_level' => $level,
            'created_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }
}
