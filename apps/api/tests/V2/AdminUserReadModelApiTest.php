<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
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

    /** @return array{public_id: string, email: string} */
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

        return ['public_id' => $publicId, 'email' => $email];
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
