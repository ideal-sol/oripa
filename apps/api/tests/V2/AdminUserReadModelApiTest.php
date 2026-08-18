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
        }
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

    public function test_unauthenticated_disabled_not_found_and_invalid_cursor_fail_closed(): void
    {
        $this->getJson('/admin/api/v2/users')->assertUnauthorized();

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

    /** @return array{id: int, public_id: string, email: string} */
    private function user(): array
    {
        $publicId = (string) Str::uuid7();
        $email = 'user-read-target-'.Str::uuid7().'@example.test';
        $userId = DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'display_name' => '表示名',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
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

        return ['id' => $userId, 'public_id' => $publicId, 'email' => $email];
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
