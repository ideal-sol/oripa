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

    public function test_authenticated_first_purchase_uses_only_succeeded_payments(): void
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
        $cacheControl = (string) $eligible->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $this->payment($user, $firstPurchase, 'failed');
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.1.eligible', true);

        $this->payment($user, $allUsers, 'succeeded');
        $this->getJson('/api/v2/point-products')
            ->assertOk()
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonPath('data.1.eligible', false)
            ->assertJsonPath('data.1.ineligible_reason', 'first_purchase_required')
            ->assertJsonPath('data.1.cta.state', 'disabled')
            ->assertJsonPath('data.1.cta.action', 'purchase');
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
            ->assertExactJson(['data' => []]);
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

    private function user(): User
    {
        $email = 'point-read-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic buyer',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function payment(User $user, object $plan, string $status): void
    {
        DB::table('payments')->insert([
            'public_id' => (string) Str::uuid7(),
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
    }
}
