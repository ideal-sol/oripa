<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PointProductReadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-14T00:00:00Z');
        $this->hideExistingPublishedPlans();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_anonymous_collection_is_public_ordered_and_contains_no_internal_ids(): void
    {
        $later = $this->plan('全員向け', 'all_users', 20, freePoints: 100);
        $first = $this->plan('初回限定', 'first_purchase_users', 10);
        $this->plan('下書き', 'all_users', 1, status: 'draft');

        $response = $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('first_user_offer.state', 'unauthenticated')
            ->assertJsonPath('first_user_offer.blocking_payments', [])
            ->assertJsonPath('first_user_offer.blocking_payments_truncated', false)
            ->assertJsonPath('first_user_offer.expires_at', null)
            ->assertJsonPath('data.0.id', $first->public_id)
            ->assertJsonPath('data.0.audience.code', 'first_purchase_users')
            ->assertJsonPath('data.0.audience.label', '初回ユーザー')
            ->assertJsonPath('data.0.user_state', 'unauthenticated')
            ->assertJsonPath('data.0.eligible', false)
            ->assertJsonPath('data.0.ineligible_reason', 'authentication_required')
            ->assertJsonPath('data.0.cta.state', 'enabled')
            ->assertJsonPath('data.0.cta.action', 'login')
            ->assertJsonPath('data.1.id', $later->public_id)
            ->assertJsonPath('data.1.price.amount', 1000)
            ->assertJsonPath('data.1.price.currency', 'JPY')
            ->assertJsonPath('data.1.grant.paid_points', 1000)
            ->assertJsonPath('data.1.grant.bonus_points', 100)
            ->assertJsonPath('data.1.grant.total_points', 1100);

        self::assertStringContainsString(
            'public',
            (string) $response->headers->get('Cache-Control')
        );
        $serialized = (string) $response->getContent();
        foreach (['point_purchase_plan_id', 'target_user_tag_id', 'provider_code'] as $field) {
            self::assertStringNotContainsString($field, $serialized);
        }
    }

    public function test_unverified_user_gets_only_the_anonymous_public_product_projection(): void
    {
        $first = $this->plan('SMS-gate-first', 'first_purchase_users', 1);
        $user = $this->user();
        $this->payment($user, $first, 'requires_action');
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $anonymous = $this->getJson('/api/v2/point-products')->assertOk()->json();
        Auth::guard('v2_user')->setUser($user);
        $response = $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', 'unauthenticated')
            ->assertJsonPath('first_user_offer.expires_at', null);
        self::assertSame($anonymous, $response->json());
    }

    public function test_authenticated_first_user_uses_sms_window_and_product_history(): void
    {
        $allUsers = $this->plan('全員向け', 'all_users', 1);
        $firstPurchase = $this->plan('初回限定', 'first_purchase_users', 2);
        $user = $this->user();
        Auth::guard('v2_user')->setUser($user);

        $eligible = $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('data.0.id', $allUsers->public_id)
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonPath('data.0.cta.action', 'purchase')
            ->assertJsonPath('data.1.id', $firstPurchase->public_id)
            ->assertJsonPath('data.1.eligible', true)
            ->assertJsonPath('data.1.ineligible_reason', null);
        $eligible->assertJsonPath('first_user_offer.state', 'active')
            ->assertJsonPath('first_user_offer.expires_at', '2026-08-15T00:00:00Z')
            ->assertJsonPath('first_user_offer.as_of', '2026-08-14T00:00:00Z');
        $cacheControl = (string) $eligible->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $this->payment($user, $firstPurchase, 'failed');
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.1.eligible', true);

        $this->payment($user, $allUsers, 'succeeded');
        CarbonImmutable::setTestNow('2026-08-14T23:59:59Z');
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.1.eligible', true)
            ->assertJsonPath('data.1.ineligible_reason', null)
            ->assertJsonPath('data.1.cta.state', 'enabled')
            ->assertJsonPath('data.1.limited_bonus.as_of', '2026-08-14T23:59:59Z');

        CarbonImmutable::setTestNow('2026-08-15T00:00:00Z');
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('first_user_offer.state', 'expired')
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonPath('data.1.eligible', false)
            ->assertJsonPath('data.1.ineligible_reason', 'first_purchase_required')
            ->assertJsonPath('data.1.cta.state', 'disabled')
            ->assertJsonPath('data.1.cta.action', 'purchase');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockingStatuses')]
    public function test_any_product_offer_becomes_unavailable_after_one_purchase(string $status): void
    {
        $first = $this->plan('A', 'first_purchase_users', 1);
        $second = $this->plan('B', 'first_purchase_users', 2);
        $this->plan('C', 'first_purchase_users', 3);
        $user = $this->user();
        Auth::guard('v2_user')->setUser($user);
        $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonPath('data.1.eligible', true)
            ->assertJsonPath('data.2.eligible', true);
        $this->payment($user, $first, $status);
        $response = $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', 'unavailable')
            ->assertJsonPath('data.0.eligible', false)
            ->assertJsonPath('data.1.eligible', false)
            ->assertJsonPath('data.2.eligible', false);
        foreach ($response->json('data') as $product) {
            self::assertSame($response->json('first_user_offer.as_of'), $product['limited_bonus']['as_of']);
        }
        $this->payment($user, $second, 'succeeded');
        $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', 'unavailable')
            ->assertJsonPath('data.1.cta.state', 'disabled')
            ->assertJsonPath('data.1.ineligible_reason', 'first_purchase_required')
            ->assertJsonPath('first_user_offer.blocking_payments', []);
        Auth::guard('v2_user')->setUser($this->user(false));
        $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', 'unavailable')
            ->assertJsonPath('first_user_offer.expires_at', null);
    }

    public static function blockingStatuses(): array
    {
        return [['created'], ['requires_action'], ['processing'], ['succeeded']];
    }

    public function test_history_scope_is_shared_by_listing_and_creation_and_uses_historical_audience(): void
    {
        $first = $this->plan('A', 'first_purchase_users', 1);
        $second = $this->plan('B', 'first_purchase_users', 2);
        $third = $this->plan('C', 'first_purchase_users', 3);
        $normal = $this->plan('Normal', 'all_users', 4);
        // A newer master version must not reclassify the plan attached to a payment.
        $newVersion = (array) $first;
        unset($newVersion['id']);
        $newVersion['public_id'] = (string) Str::uuid7();
        $newVersion['version_no'] = 2;
        $newVersion['audience_code'] = 'all_users';
        DB::table('point_purchase_plans')->insert($newVersion);
        $eligibility = app(\App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService::class);
        foreach (['any_product', 'per_product'] as $scope) {
            config(['v2_payment.first_purchase_limit_scope' => $scope]);
            foreach (['created', 'requires_action', 'processing', 'succeeded', 'failed', 'canceled', 'expired'] as $status) {
                $user = $this->user();
                $this->payment($user, $first, $status);
                $blocked = $scope === 'any_product' && ! in_array($status, ['failed', 'canceled', 'expired'], true);
                foreach ([$second, $third] as $candidate) {
                    self::assertSame(! $blocked, $eligibility->evaluate($user, $candidate, CarbonImmutable::now())['eligible']);
                    try {
                        $eligibility->assertEligible($user, $candidate, CarbonImmutable::now());
                        self::assertFalse($blocked);
                    } catch (\App\Domain\Payment\V2\Exceptions\V2PaymentException $exception) {
                        self::assertTrue($blocked);
                        self::assertSame('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED', $exception->getMessage());
                    }
                }
                $eligibility->assertEligible($user, $normal, CarbonImmutable::now());
                self::assertTrue($eligibility->evaluate($user, $normal, CarbonImmutable::now())['eligible']);
                self::assertSame(in_array($status, ['failed', 'canceled', 'expired'], true),
                    $eligibility->evaluate($user, $first, CarbonImmutable::now())['eligible']);
            }
        }
    }

    public function test_per_product_listing_keeps_other_offers_active(): void
    {
        config(['v2_payment.first_purchase_limit_scope' => 'per_product']);
        $first = $this->plan('A', 'first_purchase_users', 1);
        $this->plan('B', 'first_purchase_users', 2);
        Auth::guard('v2_user')->setUser($user = $this->user());
        $this->payment($user, $first, 'succeeded');
        $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', 'active')
            ->assertJsonPath('data.0.eligible', false)
            ->assertJsonPath('data.1.eligible', true);
    }

    public function test_sale_period_and_target_tag_are_backend_authoritative(): void
    {
        $future = $this->plan(
            '販売前',
            'all_users',
            1,
            availableFrom: now()->addHour()->toIso8601String()
        );
        $ended = $this->plan(
            '販売終了',
            'all_users',
            2,
            availableUntil: now()->toIso8601String()
        );
        $tagId = DB::table('user_tags')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'name' => '対象会員',
            'normalized_name' => '対象会員',
            'is_active' => true,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targeted = $this->plan('対象限定', 'all_users', 3, targetTagId: $tagId);
        Auth::guard('v2_user')->setUser($this->user());

        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $future->public_id)
            ->assertJsonPath('data.0.sale_state', 'coming_soon')
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.ineligible_reason', 'sale_not_started')
            ->assertJsonPath('data.1.id', $ended->public_id)
            ->assertJsonPath('data.1.sale_state', 'ended')
            ->assertJsonPath('data.1.ineligible_reason', 'sale_ended')
            ->assertJsonPath('data.2.id', $targeted->public_id)
            ->assertJsonPath('data.2.eligible', false)
            ->assertJsonPath('data.2.ineligible_reason', 'audience_not_eligible');
    }

    public function test_empty_collection_is_canonical(): void
    {
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertExactJson(['data' => [], 'first_user_offer' => [
                'state' => 'unauthenticated', 'expires_at' => null, 'as_of' => '2026-08-14T00:00:00Z',
                'blocking_payments' => [], 'blocking_payments_truncated' => false,
            ]]);
    }

    public function test_limited_bonus_state_and_exact_boundaries_are_backend_canonical(): void
    {
        $active = $this->plan('開始境界', 'all_users', 1, freePoints: 100);
        $upcoming = $this->plan('開始前', 'all_users', 2);
        $ended = $this->plan('終了境界', 'all_users', 3);
        $disabled = $this->plan('無効', 'all_users', 4);
        $this->campaign($active, now(), now()->addHour(), 300);
        $this->campaign($upcoming, now()->addSecond(), now()->addHour(), 400);
        $this->campaign($ended, now()->subHour(), now(), 500);
        $this->campaign($disabled, now()->addSecond(), now()->addHour(), 600, false);

        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $active->public_id)
            ->assertJsonPath('data.0.grant.paid_points', 1000)
            ->assertJsonPath('data.0.grant.bonus_points', 100)
            ->assertJsonPath('data.0.grant.total_points', 1100)
            ->assertJsonPath('data.0.limited_bonus.amount', 300)
            ->assertJsonPath('data.0.limited_bonus.starts_at', '2026-08-14T00:00:00Z')
            ->assertJsonPath('data.0.limited_bonus.ends_at', '2026-08-14T01:00:00Z')
            ->assertJsonPath('data.0.limited_bonus.state', 'active')
            ->assertJsonPath('data.0.limited_bonus.as_of', '2026-08-14T00:00:00Z')
            ->assertJsonPath('data.0.limited_bonus.presentation.is_visible', true)
            ->assertJsonPath('data.0.limited_bonus.presentation.label', '期間限定ボーナスコイン')
            ->assertJsonPath('data.0.limited_bonus.presentation.amount_text', '+300コイン')
            ->assertJsonPath('data.1.id', $upcoming->public_id)
            ->assertJsonPath('data.1.limited_bonus.state', 'upcoming')
            ->assertJsonPath('data.1.limited_bonus.amount', 400)
            ->assertJsonPath('data.2.id', $ended->public_id)
            ->assertJsonPath('data.2.limited_bonus.state', 'inactive')
            ->assertJsonPath('data.2.limited_bonus.amount', 0)
            ->assertJsonPath('data.2.limited_bonus.starts_at', null)
            ->assertJsonPath('data.2.limited_bonus.ends_at', null)
            ->assertJsonPath('data.2.limited_bonus.presentation.is_visible', false)
            ->assertJsonPath('data.3.id', $disabled->public_id)
            ->assertJsonPath('data.3.limited_bonus.state', 'inactive');
    }

    public function test_pending_limits_apply_independently_per_product_and_to_the_unique_offer_list(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        config(['v2_payment.first_purchase_limit_scope' => 'per_product']);
        Auth::guard('v2_user')->setUser($user = $this->user());
        $other = $this->user();
        $payments = [];
        $products = [];
        foreach (range(1, 11) as $index) {
            $plan = $this->plan("Product $index", 'first_purchase_users', $index);
            $products[] = $plan->public_id;
            $payments[] = $this->payment($user, $plan, 'requires_action');
        }
        $this->payment($other, $plan, 'processing');
        $read = fn () => $this->getJson('/api/v2/point-products')->assertOk()->json();
        $listing = $read();
        foreach ($listing['data'] as $i => $product) {
            self::assertSame('first_purchase_pending', $product['ineligible_reason']);
            self::assertFalse($product['eligible']);
            self::assertSame([$payments[$i]], array_column($product['blocking_payments'], 'id'));
            self::assertSame([$products[$i]], $product['blocking_payments'][0]['blocks_point_product_ids']);
        }
        self::assertSame(array_slice(array_reverse($payments), 0, 10), array_column($listing['first_user_offer']['blocking_payments'], 'id'));
        self::assertTrue($listing['first_user_offer']['blocking_payments_truncated']);
        foreach (range(1, 3) as $i) $payments[] = $this->payment($user, $plan, 'requires_action');
        $listing = $read();
        self::assertSame(array_slice(array_reverse($payments), 0, 3), array_column($listing['data'][10]['blocking_payments'], 'id'));
        self::assertSame(array_slice(array_reverse($payments), 0, 10), array_column($listing['first_user_offer']['blocking_payments'], 'id'));
        config(['v2_payment.first_purchase_limit_scope' => 'any_product']);
        $listing = $read();
        foreach ($listing['first_user_offer']['blocking_payments'] as $payment) self::assertSame($products, $payment['blocks_point_product_ids']);
        self::assertCount(10, $listing['first_user_offer']['blocking_payments']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_issued_async_and_success_history_override_pending_without_releasing_the_slot(): void
    {
        $plan = $this->plan('限定', 'first_purchase_users', 1);
        Auth::guard('v2_user')->setUser($owner = $this->user());
        $this->payment($owner, $plan, 'created');
        $async = $this->payment($owner, $plan, 'requires_action');
        $row = DB::table('payments')->where('public_id', $async);
        $row->update(['payment_method' => 'konbini']);
        $history = ['payment_id' => $row->value('id'), 'from_status' => 'created', 'to_status' => 'requires_action',
            'transition_source' => 'provider_api', 'actor_type' => 'system', 'occurred_at' => now(), 'request_id' => (string) Str::uuid()];
        DB::table('payment_status_histories')->insert($history);
        $read = fn () => $this->getJson('/api/v2/point-products')->assertOk();
        $read()->assertJsonPath('data.0.ineligible_reason', 'first_purchase_pending')
            ->assertJsonPath('data.0.blocking_payments.0.id', $async)
            ->assertJsonPath('data.0.blocking_payments.0.cancel_restores_offer', false)
            ->assertJsonPath('data.0.blocking_payments.0.cancel_unavailable_reason', 'provider_not_supported');
        $row->update(['status' => 'expired']);
        $read()->assertJsonPath('data.0.ineligible_reason', 'first_purchase_required')
            ->assertJsonPath('data.0.blocking_payments', [])->assertJsonPath('first_user_offer.blocking_payments', []);
        $row->update(['payment_method' => 'credit_card']);
        $read()->assertJsonPath('data.0.ineligible_reason', 'first_purchase_pending');
        DB::table('payment_status_histories')->insert([...$history, 'to_status' => 'succeeded', 'request_id' => (string) Str::uuid()]);
        $read()->assertJsonPath('data.0.ineligible_reason', 'first_purchase_required')->assertJsonPath('data.0.blocking_payments', []);
    }

    public function test_notice_discloses_only_public_fields_and_never_promises_release_for_processing(): void
    {
        $plan = $this->plan('限定', 'first_purchase_users', 1);
        Auth::guard('v2_user')->setUser($owner = $this->user());
        $id = $this->payment($owner, $plan, 'processing');
        $row = DB::table('payments')->where('public_id', $id);
        foreach (['stripe', 'paidy', 'univapay', 'techcoin', 'fincode'] as $provider) {
            $row->update(['provider_code' => $provider, 'payment_method' => $provider === 'paidy' ? 'paidy' : 'credit_card']);
            $response = $this->getJson('/api/v2/point-products')->assertOk();
            $notice = $response->json('data.0.blocking_payments.0');
            self::assertEqualsCanonicalizing(['id', 'point_product_name', 'method', 'method_label', 'status', 'started_at',
                'blocks_point_product_ids', 'cancellable', 'cancel_unavailable_reason', 'cancel_restores_offer',
                'resumable', 'cancellation_pending', 'auto_release_at'], array_keys($notice));
            self::assertFalse($notice['cancellable'] || $notice['resumable']);
            self::assertNull($notice['auto_release_at']);
            self::assertSame($notice, $response->json('first_user_offer.blocking_payments.0'));
        }
    }

    private function plan(
        string $name,
        string $audience,
        int $sortOrder,
        int $freePoints = 0,
        string $status = 'published',
        ?string $availableFrom = null,
        ?string $availableUntil = null,
        ?int $targetTagId = null
    ): object {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'read-'.Str::lower(Str::random(16)),
            'version_no' => 1,
            'name' => $name,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => $freePoints,
            'currency' => 'JPY',
            'status' => $status,
            'sort_order' => $sortOrder,
            'audience_code' => $audience,
            'target_user_tag_id' => $targetTagId,
            'revision' => 1,
            'available_from' => $availableFrom,
            'available_until' => $availableUntil,
            'published_at' => $status === 'published' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('point_purchase_plans')->find($id);
    }

    private function hideExistingPublishedPlans(): void
    {
        $latest = DB::table('point_purchase_plans')
            ->selectRaw('code, MAX(version_no) AS version_no')
            ->groupBy('code');
        $plans = DB::table('point_purchase_plans as plan')
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.code', '=', 'plan.code')
                ->on('latest.version_no', '=', 'plan.version_no'))
            ->where('plan.status', 'published')
            ->get(['plan.*']);

        foreach ($plans as $plan) {
            DB::table('point_purchase_plans')->insert([
                'public_id' => (string) Str::uuid7(),
                'code' => $plan->code,
                'version_no' => (int) $plan->version_no + 1,
                'name' => $plan->name,
                'amount' => $plan->amount,
                'paid_point_amount' => $plan->paid_point_amount,
                'free_point_amount' => $plan->free_point_amount,
                'currency' => $plan->currency,
                'status' => 'draft',
                'sort_order' => $plan->sort_order,
                'audience_code' => $plan->audience_code,
                'target_user_tag_id' => $plan->target_user_tag_id,
                'revision' => 1,
                'available_from' => $plan->available_from,
                'available_until' => $plan->available_until,
                'published_at' => null,
                'retired_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function campaign(
        object $plan,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        int $amount,
        bool $enabled = true
    ): void {
        DB::table('point_purchase_plan_limited_bonus_campaigns')->insert([
            'public_id' => (string) Str::uuid7(),
            'point_purchase_plan_id' => $plan->id,
            'is_enabled' => $enabled,
            'starts_at' => CarbonImmutable::parse($startsAt)->utc()->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc()->toIso8601String(),
            'bonus_point_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(bool $qualified = true): User
    {
        $email = 'point-read-'.Str::uuid7().'@example.test';

        return \Tests\V2\Fixtures\SmsVerifiedUser::create([
            'display_name' => 'Synthetic buyer',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'first_sms_verified_at' => $qualified ? now() : null,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function payment(User $user, object $plan, string $status): string
    {
        $publicId = (string) Str::uuid7();
        DB::table('payments')->insert([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'point_purchase_plan_id' => $plan->id,
            'provider_code' => 'synthetic',
            'provider_payment_id' => (string) Str::uuid7(),
            'status' => $status,
            'amount' => $plan->amount,
            'currency' => 'JPY',
            'paid_point_amount' => $plan->paid_point_amount,
            'free_point_amount' => $plan->free_point_amount,
            'plan_name_snapshot' => $plan->name,
            'plan_code_snapshot' => $plan->code,
            'succeeded_at' => $status === 'succeeded' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $publicId;
    }
}
