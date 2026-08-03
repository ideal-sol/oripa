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

final class DashboardSalesAggregationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config([
            'cache.default' => 'array',
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.pagination.maximum' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_and_admin_can_read_all_dashboard_reports_with_private_contract(): void
    {
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            Auth::forgetGuards();
            $client = $this->asAdmin($this->sessionToken($role));
            foreach ([
                '/admin/api/v2/reports/dashboard/sales/monthly?month=2026-08',
                '/admin/api/v2/reports/dashboard/sales/daily?date=2026-08-01',
                '/admin/api/v2/reports/dashboard/points/monthly?month=2026-08',
                '/admin/api/v2/reports/dashboard/points/daily?date=2026-08-01',
                '/admin/api/v2/reports/dashboard/reversals?start_date=2026-08-01&end_date=2026-08-31',
            ] as $path) {
                $response = $client->getJson($path)->assertOk();
                $cacheControl = $response->headers->get('Cache-Control');
                self::assertIsString($cacheControl);
                self::assertStringContainsString('private', $cacheControl);
                self::assertStringContainsString('no-store', $cacheControl);
                self::assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
            }
        }
    }

    public function test_operator_and_unauthenticated_requests_are_denied(): void
    {
        $path = '/admin/api/v2/reports/dashboard/sales/monthly?month=2026-08';
        $this->getJson($path)->assertUnauthorized();

        Auth::forgetGuards();
        $this->asAdmin($this->sessionToken(V2AdminRole::Operator))
            ->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
    }

    public function test_invalid_period_and_cursor_use_rfc_9457_problem_contract(): void
    {
        $client = $this->asAdmin($this->sessionToken(V2AdminRole::Owner));
        $response = $client
            ->getJson('/admin/api/v2/reports/dashboard/reversals?start_date=2026-08-02&end_date=2026-08-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORTING_PERIOD_INVALID');
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type')
        );

        $client
            ->getJson('/admin/api/v2/reports/dashboard/sales/daily?date=2026-08-01&cursor=internal-id')
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORTING_CURSOR_INVALID');
    }

    public function test_public_response_excludes_internal_and_provider_identifiers(): void
    {
        $userId = $this->user();
        DB::table('payments')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'point_purchase_plan_id' => null,
            'provider_code' => 'synthetic',
            'provider_payment_id' => 'private-provider-reference',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'JPY',
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'plan_name_snapshot' => 'Synthetic Plan',
            'plan_code_snapshot' => 'synthetic-plan',
            'succeeded_at' => '2026-07-31T15:00:00Z',
            'points_granted_at' => '2026-07-31T15:00:00Z',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->asAdmin($this->sessionToken(V2AdminRole::Owner))
            ->getJson('/admin/api/v2/reports/dashboard/sales/daily?date=2026-08-01')
            ->assertOk()
            ->json();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-provider-reference', $encoded);
        self::assertStringNotContainsString('password', $encoded);
        self::assertArrayNotHasKey('id', $payload['items'][0]);
    }

    private function sessionToken(V2AdminRole $role): string
    {
        $email = 'dashboard-api-'.Str::uuid7().'@example.test';
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

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function user(): int
    {
        $email = 'dashboard-api-user-'.Str::uuid7().'@example.test';

        return DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
