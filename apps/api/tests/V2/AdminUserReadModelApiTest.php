<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserReadModelApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config([
            'cache.default' => 'array',
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_all_active_admin_roles_can_read_list_detail_and_history(): void
    {
        $user = $this->user();
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            Auth::forgetGuards();
            $client = $this->asAdmin($this->sessionToken($role));
            $list = $client->getJson('/admin/api/v2/users?limit=20')->assertOk();
            $list->assertJsonPath('items.0.id', $user['public_id'])
                ->assertJsonPath('items.0.display_name', '表示名')
                ->assertJsonPath('items.0.point_balance.total_balance', 300)
                ->assertJsonMissingPath('items.0.email');
            $this->assertPrivateContract($list);

            $detail = $client->getJson('/admin/api/v2/users/'.$user['public_id'])
                ->assertOk()
                ->assertJsonPath('data.id', $user['public_id'])
                ->assertJsonPath('data.email', $user['email'])
                ->assertJsonPath('data.state_revision', 1)
                ->assertJsonPath('data.point_balance.total_balance', 270)
                ->assertJsonPath('data.point_balance.paid_balance', 90)
                ->assertJsonPath('data.point_balance.free_balance', 180)
                ->assertJsonPath('data.point_balance.next_expiring_amount', 0)
                ->assertJsonPath('data.point_balance.next_expires_at', null)
                ->assertJsonPath('data.tag_assignment_revision', 1)
                ->assertJsonCount(0, 'data.tags')
                ->assertJsonMissingPath('data.password_hash')
                ->assertJsonMissingPath('data.email_normalized');
            $this->assertPrivateContract($detail);

            $history = $client
                ->getJson('/admin/api/v2/users/'.$user['public_id'].'/gacha-history')
                ->assertOk()
                ->assertJsonPath('user_id', $user['public_id'])
                ->assertJsonCount(0, 'items');
            $this->assertPrivateContract($history);

            $referrals = $client
                ->getJson('/admin/api/v2/users/'.$user['public_id'].'/referral-history')
                ->assertOk()
                ->assertJsonPath('user_id', $user['public_id'])
                ->assertJsonCount(0, 'items');
            $this->assertPrivateContract($referrals);
        }
    }

    public function test_referral_history_is_referrer_only_and_cursor_paginated(): void
    {
        $referrer = $this->user('紹介者A');
        $firstReferred = $this->user('紹介先B');
        $secondReferred = $this->user('紹介先C');
        $thirdReferred = $this->user(null);
        $otherReferrer = $this->user('別の紹介者');
        $otherReferred = $this->user('別の紹介先');

        $this->referral($referrer['id'], $firstReferred['id'], 'pending', '2026-08-21T00:00:00Z');
        $this->referral($referrer['id'], $secondReferred['id'], 'rewarded', '2026-08-22T00:00:00Z');
        $this->referral($referrer['id'], $thirdReferred['id'], 'canceled', '2026-08-23T00:00:00Z');
        $this->referral($otherReferrer['id'], $referrer['id'], 'rewarded', '2026-08-20T00:00:00Z');
        $this->referral($otherReferrer['id'], $otherReferred['id'], 'pending', '2026-08-19T00:00:00Z');

        $pointOperationCount = DB::table('point_operations')->count();
        $referralRows = DB::table('user_referrals')->orderBy('id')->get()->toJson();
        $client = $this->asAdmin($this->sessionToken(V2AdminRole::Operator));
        $firstPage = $client
            ->getJson('/admin/api/v2/users/'.$referrer['public_id'].'/referral-history?limit=2')
            ->assertOk()
            ->assertJsonPath('user_id', $referrer['public_id'])
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.referred_user_id', $thirdReferred['public_id'])
            ->assertJsonPath('items.0.referred_user_display_name', null)
            ->assertJsonPath('items.0.status', 'canceled')
            ->assertJsonPath('items.0.referred_at', '2026-08-23T00:00:00+00:00')
            ->assertJsonPath('items.0.registered_at', $thirdReferred['created_at'])
            ->assertJsonPath('items.1.referred_user_id', $secondReferred['public_id'])
            ->assertJsonPath('items.1.status', 'rewarded');
        $this->assertPrivateContract($firstPage);
        $cursor = $firstPage->json('next_cursor');
        self::assertIsString($cursor);

        $secondPage = $client
            ->getJson('/admin/api/v2/users/'.$referrer['public_id'].'/referral-history?limit=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.referred_user_id', $firstReferred['public_id'])
            ->assertJsonPath('next_cursor', null);
        $this->assertPrivateContract($secondPage);

        $returnedIds = collect([
            ...$firstPage->json('items'),
            ...$secondPage->json('items'),
        ])->pluck('referred_user_id')->all();
        self::assertSame([
            $thirdReferred['public_id'],
            $secondReferred['public_id'],
            $firstReferred['public_id'],
        ], $returnedIds);
        self::assertNotContains($referrer['public_id'], $returnedIds);
        self::assertNotContains($otherReferred['public_id'], $returnedIds);
        self::assertSame($pointOperationCount, DB::table('point_operations')->count());
        self::assertSame($referralRows, DB::table('user_referrals')->orderBy('id')->get()->toJson());
    }

    public function test_detail_returns_available_balances_and_the_next_exact_expiry_bucket(): void
    {
        CarbonImmutable::setTestNow('2026-08-18T00:00:00Z');

        try {
            $user = $this->user();
            DB::table('wallets')->where('user_id', $user['id'])->update([
                'paid_balance' => 120,
                'free_balance' => 190,
                'paid_reserved_balance' => 0,
                'free_reserved_balance' => 10,
            ]);
            $this->lot($user['id'], 40, 10, CarbonImmutable::now()->addDays(2));
            $this->lot($user['id'], 20, 0, CarbonImmutable::now()->addDays(2));
            $this->lot($user['id'], 100, 0, CarbonImmutable::now()->addDays(3));
            $this->lot($user['id'], 30, 0, CarbonImmutable::now()->subSecond());

            $detail = $this->asAdmin($this->sessionToken(V2AdminRole::Owner))
                ->getJson('/admin/api/v2/users/'.$user['public_id'])
                ->assertOk()
                ->assertJsonPath('data.point_balance.total_balance', 270)
                ->assertJsonPath('data.point_balance.paid_balance', 120)
                ->assertJsonPath('data.point_balance.free_balance', 150)
                ->assertJsonPath('data.point_balance.next_expiring_amount', 50)
                ->assertJsonPath(
                    'data.point_balance.next_expires_at',
                    '2026-08-20T00:00:00+00:00'
                );
            $this->assertPrivateContract($detail);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_user_filters_are_server_side_and_cursor_stable(): void
    {
        $activeFirst = $this->user('有効A', 'active', '2026-08-20T14:59:59Z');
        $activeSecond = $this->user('有効B', 'active', '2026-08-20T15:00:00Z');
        $failed = $this->user('認証失敗', 'verification_failed', '2026-08-21T03:00:00Z');
        $this->user('停止', 'suspended', '2026-08-21T03:00:00Z');
        $client = $this->asAdmin($this->sessionToken(V2AdminRole::Operator));

        $client->getJson('/admin/api/v2/users?status=verification_failed')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $failed['public_id'])
            ->assertJsonPath('items.0.status', 'verification_failed');
        $client->getJson('/admin/api/v2/users?user_id='.$activeFirst['public_id'])
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $activeFirst['public_id']);
        $client->getJson('/admin/api/v2/users?status=active&date_from=2026-08-21&date_to=2026-08-21')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $activeSecond['public_id']);
        $client->getJson('/admin/api/v2/users?user_id='.$failed['public_id'].'&status=verification_failed&date_from=2026-08-21&date_to=2026-08-21')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $failed['public_id']);

        $firstPage = $client->getJson('/admin/api/v2/users?status=active&limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'items');
        $cursor = $firstPage->json('next_cursor');
        self::assertIsString($cursor);
        $client->getJson('/admin/api/v2/users?status=active&limit=1&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.status', 'active');

        foreach ([
            'user_id=partial-id',
            'status=unknown',
            'date_from=2026-02-30',
            'date_from=2026-08-22&date_to=2026-08-21',
        ] as $query) {
            $client->getJson('/admin/api/v2/users?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('code', 'ADMIN_USER_QUERY_INVALID');
        }
    }

    public function test_unauthenticated_disabled_not_found_and_invalid_cursor_fail_closed(): void
    {
        $this->getJson('/admin/api/v2/users')->assertUnauthorized();
        $this->getJson('/admin/api/v2/users/'.Str::uuid7().'/referral-history')->assertUnauthorized();

        $token = $this->sessionToken(V2AdminRole::Operator);
        DB::table('admins')->where('email_normalized', 'like', 'user-read-api-%')
            ->update(['state' => 'disabled']);
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/users')->assertUnauthorized();

        Auth::forgetGuards();
        $client = $this->asAdmin($this->sessionToken(V2AdminRole::Owner));
        $missing = (string) Str::uuid7();
        $response = $client->getJson('/admin/api/v2/users/'.$missing)
            ->assertNotFound()
            ->assertJsonPath('code', 'ADMIN_USER_NOT_FOUND');
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type')
        );
        $client->getJson('/admin/api/v2/users?cursor=internal-id')
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORTING_CURSOR_INVALID');
    }

    public function test_response_never_exposes_internal_or_authentication_fields(): void
    {
        $this->user();
        $payload = $this->asAdmin($this->sessionToken(V2AdminRole::Owner))
            ->getJson('/admin/api/v2/users')
            ->assertOk()
            ->json();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['password_hash', 'email_normalized', 'session_id_hash', 'wallet_id'] as $field) {
            self::assertStringNotContainsString($field, $encoded);
        }
        self::assertArrayNotHasKey('email', $payload['items'][0]);
    }

    private function sessionToken(V2AdminRole $role): string
    {
        $email = 'user-read-api-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
            'revoked_at' => null,
        ]);

        return $token;
    }

    /** @return array{id: int, public_id: string, email: string, created_at: string} */
    private function user(
        ?string $displayName = '表示名',
        string $state = 'active',
        ?string $createdAt = null
    ): array
    {
        $publicId = (string) Str::uuid7();
        $email = 'user-read-target-'.Str::uuid7().'@example.test';
        $createdAt ??= now()->startOfSecond()->toIso8601String();
        $userId = DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'display_name' => $displayName,
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => $state === 'verification_failed' ? null : now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => $state,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('wallets')->insert([
            'user_id' => $userId,
            'paid_balance' => 100,
            'free_balance' => 200,
            'paid_reserved_balance' => 10,
            'free_reserved_balance' => 20,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $userId,
            'public_id' => $publicId,
            'email' => $email,
            'created_at' => CarbonImmutable::parse($createdAt)->utc()->toIso8601String(),
        ];
    }

    private function referral(
        int $referrerUserId,
        int $referredUserId,
        string $status,
        string $createdAt
    ): void {
        DB::table('user_referrals')->insert([
            'public_id' => (string) Str::uuid7(),
            'referrer_user_id' => $referrerUserId,
            'referred_user_id' => $referredUserId,
            'referral_code' => 'LP'.Str::random(10),
            'status' => $status,
            'reward_enabled' => false,
            'referrer_point_amount' => 0,
            'referred_user_point_amount' => 0,
            'reward_expiration_days' => 180,
            'rewarded_at' => $status === 'rewarded' ? $createdAt : null,
            'canceled_at' => $status === 'canceled' ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function lot(
        int $userId,
        int $remainingAmount,
        int $reservedAmount,
        CarbonImmutable $expiresAt
    ): void {
        $operationId = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'operation_type' => 'free_grant',
            'business_key' => 'admin.user.read.lot:'.Str::uuid7(),
            'source_type' => 'admin_adjustment',
            'source_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'is_qa' => false,
            'qa_draw_execution_id' => null,
            'occurred_at' => now(),
            'business_date' => now()->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => now(),
        ]);
        DB::table('point_lots')->insert([
            'user_id' => $userId,
            'grant_operation_id' => $operationId,
            'point_type' => 'free',
            'granted_amount' => $remainingAmount,
            'remaining_amount' => $remainingAmount,
            'reserved_amount' => $reservedAmount,
            'granted_at' => now(),
            'expire_at' => $expiresAt->toIso8601String(),
            'legacy_no_expiry' => false,
        ]);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function assertPrivateContract(\Illuminate\Testing\TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }
}
