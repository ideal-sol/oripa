<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AccountClosurePoints;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2UserClosureService;
use App\Domain\Point\Services\V2PointLedgerService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountClosureTest extends TestCase
{
    use \Tests\Support\MemberRankGachaFixture;
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function user(bool $preRegistered = false): User
    {
        return User::query()->create([
            'email_display' => Str::uuid().'@example.test', 'email_normalized' => Str::uuid().'@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('closure test password'),
            'state' => V2UserState::Active, 'password_login_enabled' => true,
            'created_at' => now(), 'pre_registered_at' => $preRegistered ? now() : null,
        ]);
    }

    public function test_available_points_are_forfeited_once_and_ledger_rebuild_matches(): void
    {
        $user = $this->user();
        app(V2PointService::class)->grantFree((int) $user->id, 12500, (string) Str::uuid());
        $service = app(V2UserClosureService::class);
        self::assertSame(12500, $service->preview($user)['point_balance']);
        $result = $service->closeLocked($user, 'admin', 'normal', (string) Str::uuid());
        self::assertArrayHasKey('closed_at', $result);
        self::assertSame(V2UserState::Closed, $user->refresh()->state);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild((int) $user->id));
        self::assertSame(-12500, (int) DB::table('point_ledger_entries')->where('entry_type', 'forfeit')->where('user_id', $user->id)->sum('amount_delta'));
        self::assertSame($result, $service->closeLocked($user, 'admin', 'normal', (string) Str::uuid()));
        self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('event_key', 'user.closed:'.$user->public_id)->count());
        $this->rankClock('2026-10-31T15:00:01Z');
        $snapshot = app(\App\Domain\Point\Services\V2PointSnapshotService::class)->generate('2026-10-31');
        self::assertSame(12500, $snapshot->expired_free_amount);
        self::assertSame(0, $snapshot->closing_free_balance);
        $reconciliation = app(\App\Domain\Point\Services\V2PointReconciliationService::class)->run('2026-10-31');
        self::assertSame(0, $reconciliation->discrepancy_count);
    }

    public function test_forced_closure_does_not_schedule_completion_email(): void
    {
        $user = $this->user();
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'forced', (string) Str::uuid());
        self::assertDatabaseHas('account_closures', ['user_id' => $user->id, 'closure_kind' => 'forced']);
        self::assertSame(0, DB::table('mail_deliveries')->where('event_key', 'user.closed:'.$user->public_id)->count());
    }

    public function test_queued_regular_mail_is_suppressed_but_completion_is_delivered_once(): void
    {
        $user = $this->user();
        $mail = app(\App\Domain\Mail\Services\V2TemplateMailDeliveryService::class);
        $mail->schedule('registration_completed', 'closure-welcome:'.$user->public_id, 'user', $user->public_id);
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        \Illuminate\Support\Facades\Mail::shouldReceive('html')->once()->withArgs(
            static fn (string $body, callable $callback): bool => str_contains($body, '決済関連のお知らせメール')
        );
        foreach (DB::table('mail_deliveries')->where('source_public_id', $user->public_id)->get() as $delivery) {
            $mail->deliver($delivery->public_id);
            $mail->deliver($delivery->public_id);
        }
        self::assertDatabaseHas('mail_deliveries', ['event_key' => 'closure-welcome:'.$user->public_id, 'status' => 'failed']);
        self::assertDatabaseHas('mail_deliveries', ['event_key' => 'user.closed:'.$user->public_id, 'status' => 'sent', 'attempts' => 1]);
    }

    public function test_closure_history_and_negative_wallet_checks_cannot_be_bypassed(): void
    {
        $user = $this->user();
        app(V2PointService::class)->grantFree($user->id, 100, (string) Str::uuid7());
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        foreach ([
            fn () => DB::table('account_closures')->where('user_id', $user->id)->update(['completed_at' => now()->addDay()]),
            fn () => DB::table('account_closures')->where('user_id', $user->id)->delete(),
            fn () => DB::table('wallets')->where('user_id', $user->id)->update(['free_balance' => -1]),
        ] as $mutation) {
            try { DB::transaction($mutation); self::fail('Database invariant must reject mutation.'); }
            catch (\Illuminate\Database\QueryException $error) { self::assertContains($error->getCode(), ['P0001', '23514']); }
        }
        self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 0]);
    }

    public function test_email_verification_alone_is_not_sufficient(): void
    {
        $this->expectException(V2AuthenticationException::class);
        app(V2UserClosureService::class)->assertEligible($this->user());
    }

    public function test_later_manual_or_referral_credit_never_recreates_usable_closed_balance(): void
    {
        DB::table('referral_point_settings')->where('id', 1)->update(['is_enabled' => true, 'referrer_point_amount' => 100, 'referred_user_point_amount' => 100]);
        $user = $this->user();
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        $adjustments = app(\App\Domain\Point\Services\V2AdminPointAdjustmentService::class);
        $admin = $this->rankAdmin(); $key = (string) Str::uuid7();
        $input = ['point_type' => 'free', 'direction' => 'grant', 'amount' => 100, 'reason' => 'Synthetic postclosure correction'];
        $first = $adjustments->execute($admin, $user->public_id, $key, $input);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($adjustments->execute($admin, $user->public_id, $key, $input)['idempotent_replay']);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild($user->id));
        $new = $this->user();
        $referrals = app(\App\Domain\Referral\Services\V2ReferralRewardService::class);
        $referrals->record($new, $user->referral_code);
        self::assertSame('rewarded', $referrals->rewardForReferredUser($new)->status);
        self::assertDatabaseHas('wallets', ['user_id' => $new->id, 'free_balance' => 100]);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild($user->id));
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 0]);
    }

    public function test_financial_stop_retains_completion_and_does_not_change_other_user(): void
    {
        $old = $this->user();
        $service = app(V2UserClosureService::class);
        $result = $service->closeLocked($old, 'admin', 'normal', (string) Str::uuid());
        $new = $this->user();
        $new->forceFill(['email_normalized' => $old->email_normalized, 'email_display' => $old->email_display])->save();
        $service->financialStop((int) $old->id);
        self::assertSame(V2UserState::Suspended, $old->refresh()->state);
        self::assertSame(V2UserState::Active, $new->refresh()->state);
        self::assertSame($result, $service->closeLocked($old, 'admin', 'normal', (string) Str::uuid()));
        $revision = $old->state_revision;
        $service->financialStop((int) $old->id);
        self::assertSame($revision, $old->refresh()->state_revision);
    }

    public function test_closure_restore_is_bounded_by_own_forfeiture_and_can_be_reforfeited(): void
    {
        $user = $this->user();
        app(V2PointService::class)->grantFree((int) $user->id, 8000, (string) Str::uuid());
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid());
        $points = app(V2AccountClosurePoints::class);
        $lots = DB::table('point_lots')->where('user_id', $user->id)->get();
        self::assertSame(8000, $points->outstanding((int) $lots[0]->id));
        $points->restoreForAdjustment((int) $user->id, $lots);
        self::assertSame(0, $points->outstanding((int) $lots[0]->id));
        $points->restoreForAdjustment((int) $user->id, $lots);
        self::assertSame(8000, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(8000, $points->forfeit((int) $user->id));
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild((int) $user->id));
    }

    public function test_late_refund_remains_possible_and_cancel_never_restores_usable_points(): void
    {
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 1000, 100);
        $closure = app(V2UserClosureService::class);
        $closure->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        $admin = $this->rankAdmin();
        $adjustments = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $prepared = $adjustments->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic closure refund'], (string) Str::uuid7());
        self::assertSame('points_reserved', $prepared['data']['status']);
        self::assertSame(0, $closure->preview($user)['point_balance']);
        self::assertSame(V2UserState::Suspended, $user->refresh()->state);
        $adjustments->write($admin, $payment->public_id, 'cancel', ['reason' => 'Synthetic cancellation'], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0, 'paid_reserved_balance' => 0]);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild((int) $user->id));
    }

    public function test_reserved_refund_survives_self_closure_and_completes_with_rank_adjustment(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 2000);
        app(V2PointService::class)->grantFree($user->id, 8000, (string) Str::uuid7());
        $admin = $this->rankAdmin();
        $adjustments = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $prepared = $adjustments->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic refund'], (string) Str::uuid7());
        $closure = app(V2UserClosureService::class);
        self::assertSame(8000, $closure->preview($user)['point_balance']);
        $closure->closeLocked($user, 'self', 'normal', (string) Str::uuid7());
        self::assertSame(V2UserState::Suspended, $user->refresh()->state);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 2000, 'paid_reserved_balance' => 2000, 'free_balance' => 0]);
        $frozen = (array) DB::table('member_rank_states')->where('user_id', $user->id)->first();
        $done = $adjustments->write($admin, $payment->public_id, 'complete', ['reason' => 'Synthetic confirmation', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame('succeeded', $done['data']['status']);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild((int) $user->id));
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_reserved_balance' => 0]);
        self::assertSame($frozen, (array) DB::table('member_rank_states')->where('user_id', $user->id)->first());
        self::assertDatabaseHas('member_rank_point_entries', ['user_id' => $user->id, 'reason' => 'payment_adjustment', 'applied_delta' => -2000]);
    }

    public function test_late_chargeback_preserves_preclosure_shortfall_and_original_payment(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 1000, 100);
        $admin = $this->rankAdmin();
        foreach (['free' => 100, 'paid' => 50] as $type => $amount) {
            app(V2PointService::class)->applyAdminAdjustmentWithinTransaction($user->id, $admin->adminId, $type, 'deduct',
                $amount, 'point.admin_adjustment:'.hash('sha256', (string) Str::uuid7()), now());
        }
        $before = app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->previewChargeback(DB::table('payments')->find($payment->id));
        self::assertSame(50, $before['shortfall_paid_amount']);
        self::assertSame(100, $before['shortfall_free_amount']);
        app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        $result = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class)->write($this->rankAdmin(), $payment->public_id, 'chargeback', ['reason' => 'Synthetic chargeback'], (string) Str::uuid7());
        self::assertSame(50, $result['data']['impact']['shortfall_paid_amount']);
        self::assertSame(100, $result['data']['impact']['shortfall_free_amount']);
        self::assertSame(950, $result['data']['impact']['reversed_paid_from_paid']);
        self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild((int) $user->id));
    }

    public function test_verified_late_payment_has_no_grant_and_only_same_jst_day_schedules_mail(): void
    {
        $this->enableRankFixture();
        foreach ([false, true] as $nextDay) {
            $this->rankClock('2026-10-31T14:59:59Z');
            $user = $this->rankUser();
            [$payment] = $this->rankPayment($user, 1000);
            app(V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
            if ($nextDay) $this->rankClock('2026-10-31T15:00:00Z');
            $payments = app(\App\Domain\Payment\V2\Services\V2PaymentService::class);
            $event = $payments->recordVerifiedProviderEvent('fincode', 'closure-late:'.Str::uuid7(), 'payment.succeeded', '{}', [], $payment->id, null, now()->subMinute());
            $payments->confirmSucceeded($event->id);
            $payments->confirmSucceeded($event->id);
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertSame(1, DB::table('account_closure_late_payments')->where('payment_id', $payment->id)->count());
            self::assertSame($nextDay ? 0 : 1, DB::table('mail_deliveries')->where('event_key', 'account.closure.payment:'.$payment->public_id)->count());
            self::assertSame('canceled', DB::table('payments')->where('id', $payment->id)->value('status'));
        }
    }

    public function test_processing_payment_blocks_before_any_closure_mutation(): void
    {
        $user = $this->rankUser();
        [$payment] = $this->rankPayment($user, 1000);
        app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->applyProviderStartResult($payment->id, 'processing', 'processing');
        $service = app(V2UserClosureService::class);
        self::assertFalse($service->preview($user)['can_close']);
        try {
            $service->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
            self::fail('Processing payment must block closure.');
        } catch (V2AuthenticationException $error) {
            self::assertSame('ACCOUNT_CLOSURE_BLOCKED', $error->errorCode);
        }
        self::assertSame(V2UserState::Active, $user->refresh()->state);
        self::assertSame(0, DB::table('account_closures')->where('user_id', $user->id)->count());
    }

    public function test_reused_phone_disqualifies_without_any_purchase_and_survives_hmac_rotation(): void
    {
        DB::table('referral_point_settings')->where('id', 1)->update(['is_enabled' => true, 'referrer_point_amount' => 100, 'referred_user_point_amount' => 100]);
        $old = $this->user();
        $phone = '+819012345678';
        $hash = app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)->hash($phone);
        DB::table('user_phone_numbers')->insert(['public_id' => (string) Str::uuid7(), 'user_id' => $old->id, 'phone_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($phone),
            'phone_hmac' => $hash, 'verified_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        app(V2UserClosureService::class)->closeLocked($old, 'admin', 'normal', (string) Str::uuid7());
        config(['v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('z', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => []]);
        $new = $this->user(true);
        DB::table('user_phone_numbers')->insert(['public_id' => (string) Str::uuid7(), 'user_id' => $new->id, 'phone_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($phone),
            'phone_hmac' => app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)->hash($phone), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $benefits = app(\App\Domain\Identity\Services\V2PhoneBenefitEligibility::class);
        self::assertTrue($benefits->reused($new->id));
        $new->forceFill(['first_registration_qualified_at' => now(), 'first_sms_verified_at' => now()])->save();
        $firstPlan = (object) ['audience_code' => 'first_purchase_users', 'code' => 'synthetic-first'];
        $purchases = app(\App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService::class);
        self::assertFalse($purchases->eligible($new, $firstPlan, \Carbon\CarbonImmutable::now()));
        self::assertTrue($purchases->eligible($new, (object) ['audience_code' => 'all_users'], \Carbon\CarbonImmutable::now()));
        self::assertSame('phone_already_rewarded', app(\App\Domain\Identity\Services\V2PreRegistrationService::class)->status($new)['reason']);
        $referrer = $this->user();
        $referrals = app(\App\Domain\Referral\Services\V2ReferralRewardService::class);
        $referrals->record($new, $referrer->referral_code);
        self::assertSame('canceled', $referrals->rewardForReferredUser($new)->status);
        $referrals->rewardForReferredUser($new);
        self::assertSame(0, DB::table('point_operations')->whereIn('user_id', [$new->id, $referrer->id])->count());
        $this->importRankGachas();
        $signup = $this->rankGacha('signup_once');
        $version = DB::table('catalog_gacha_versions')->find($signup->published_version_id);
        self::assertSame('registration_not_qualified', app(\App\Domain\Draw\Services\V2DrawEligibilityService::class)->evaluateLogin($new, $signup, $version, \Carbon\CarbonImmutable::now())['reason']);
        self::assertSame('none', $version->member_rank_mode);
        self::assertFalse($benefits->banned(app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)->hashes($phone)));
        app(V2UserClosureService::class)->financialStop($old->id);
        self::assertTrue($benefits->banned(app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)->hashes($phone)));
        self::assertSame(V2UserState::Active, $new->refresh()->state);
    }
}
