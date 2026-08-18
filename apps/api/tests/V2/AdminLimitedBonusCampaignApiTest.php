<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2PointPurchasePlanException;
use App\Domain\Payment\V2\Services\V2AdminLimitedBonusCampaignService;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminLimitedBonusCampaignApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('l', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        CarbonImmutable::setTestNow('2026-08-18T00:00:00Z');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_admin_lists_creates_and_updates_exact_plan_version_campaigns(): void
    {
        $service = app(V2AdminLimitedBonusCampaignService::class);
        $context = $this->context(V2AdminRole::Admin);
        [$versionOne, $versionTwo] = $this->versionedPlans();
        $payload = $this->payload();
        $key = (string) Str::uuid7();

        $created = $service->create($context, $versionOne->public_id, $payload, $key);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue($service->create(
            $context,
            $versionOne->public_id,
            $payload,
            $key
        )['idempotent_replay']);
        self::assertSame($versionOne->public_id, $created['data']['point_purchase_plan_id']);
        self::assertSame(1, $created['data']['point_purchase_plan_version']);
        self::assertCount(1, $service->listing($context, $versionOne->public_id));
        self::assertCount(0, $service->listing($context, $versionTwo->public_id));

        $versionTwoCampaign = $service->create(
            $context,
            $versionTwo->public_id,
            $payload,
            (string) Str::uuid7()
        );
        self::assertSame(2, $versionTwoCampaign['data']['point_purchase_plan_version']);

        $updated = $service->update(
            $context,
            $versionOne->public_id,
            $created['data']['id'],
            [...$payload, 'is_enabled' => false, 'bonus_point_amount' => 450],
            (string) Str::uuid7()
        );
        self::assertFalse($updated['data']['is_enabled']);
        self::assertSame(450, $updated['data']['bonus_point_amount']);
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'payment.limited_bonus_campaign.updated')->count());

        try {
            $service->update(
                $context,
                $versionTwo->public_id,
                $created['data']['id'],
                $payload,
                (string) Str::uuid7()
            );
            self::fail('A campaign must not cross exact plan versions.');
        } catch (V2PointPurchasePlanException $exception) {
            self::assertSame('LIMITED_BONUS_CAMPAIGN_NOT_FOUND', $exception->errorCode);
        }
    }

    public function test_domain_validation_and_overlap_errors_propagate_to_admin_codes(): void
    {
        $service = app(V2AdminLimitedBonusCampaignService::class);
        $context = $this->context(V2AdminRole::Owner);
        [, $plan] = $this->versionedPlans();
        foreach ([
            [...$this->payload(), 'bonus_point_amount' => 0],
            [...$this->payload(), 'starts_at' => '2026-08-20T00:00:00Z',
                'ends_at' => '2026-08-20T00:00:00Z'],
            [...$this->payload(), 'starts_at' => 'not-a-date'],
        ] as $payload) {
            try {
                $service->create($context, $plan->public_id, $payload, (string) Str::uuid7());
                self::fail('Invalid campaign input must fail.');
            } catch (V2PointPurchasePlanException $exception) {
                self::assertSame('LIMITED_BONUS_CAMPAIGN_INVALID', $exception->errorCode);
                self::assertSame(422, $exception->status);
            }
        }

        $service->create($context, $plan->public_id, $this->payload(), (string) Str::uuid7());
        try {
            $service->create($context, $plan->public_id, [
                ...$this->payload(),
                'starts_at' => '2026-08-20T12:00:00Z',
                'ends_at' => '2026-08-22T00:00:00Z',
            ], (string) Str::uuid7());
            self::fail('An overlapping campaign must fail.');
        } catch (V2PointPurchasePlanException $exception) {
            self::assertSame('LIMITED_BONUS_CAMPAIGN_OVERLAP', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
    }

    public function test_permissions_and_routes_fail_closed(): void
    {
        $service = app(V2AdminLimitedBonusCampaignService::class);
        $operator = $this->context(V2AdminRole::Operator);
        [, $plan] = $this->versionedPlans();
        self::assertSame([], $service->listing($operator, $plan->public_id));
        try {
            $service->create($operator, $plan->public_id, $this->payload(), (string) Str::uuid7());
            self::fail('Operator mutation must be denied.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }

        $this->getJson('/admin/api/v2/point-purchase-plans/'.$plan->public_id.'/limited-bonus-campaigns')
            ->assertUnauthorized();
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'limited-bonus-campaigns'))
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' '.$route->uri()));
        self::assertContains(
            'GET admin/api/v2/point-purchase-plans/{planId}/limited-bonus-campaigns',
            $routes
        );
        self::assertContains(
            'POST admin/api/v2/point-purchase-plans/{planId}/limited-bonus-campaigns',
            $routes
        );
        self::assertContains(
            'PUT admin/api/v2/point-purchase-plans/{planId}/limited-bonus-campaigns/{campaignId}',
            $routes
        );
    }

    /** @return array{0: object, 1: object} */
    private function versionedPlans(): array
    {
        $code = 'campaign-'.Str::lower(Str::random(12));
        $firstId = $this->plan($code, 1, 'retired');
        $secondId = $this->plan($code, 2, 'published');

        return [
            DB::table('point_purchase_plans')->find($firstId),
            DB::table('point_purchase_plans')->find($secondId),
        ];
    }

    private function plan(string $code, int $version, string $status): int
    {
        return DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => $code,
            'version_no' => $version,
            'name' => 'Campaign Plan '.$version,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
            'status' => $status,
            'sort_order' => 10,
            'audience_code' => 'all_users',
            'revision' => 1,
            'published_at' => now(),
            'retired_at' => $status === 'retired' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'is_enabled' => true,
            'starts_at' => '2026-08-20T00:00:00Z',
            'ends_at' => '2026-08-21T00:00:00Z',
            'bonus_point_amount' => 300,
        ];
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'limited-bonus-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $sessionHash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7),
        ]);

        return new V2AdminAuthorizationContext(
            $admin->id,
            $admin->public_id,
            $role,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }
}
