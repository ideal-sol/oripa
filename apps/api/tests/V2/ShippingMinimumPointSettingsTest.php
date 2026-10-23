<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class ShippingMinimumPointSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'oripa.free_point_expiration_days' => 180,
        ]);
        Cache::store('array')->clear();
        Carbon::setTestNow('2026-08-06T12:00:00Z');
        CarbonImmutable::setTestNow('2026-08-06T12:00:00Z');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_role_authorization_validation_occ_replay_audit_and_noop(): void
    {
        $path = '/admin/api/v2/settings/shipping-minimum-points';
        $this->getJson($path)->assertUnauthorized();
        foreach (V2AdminRole::cases() as $role) {
            Auth::forgetGuards();
            $token = $this->adminSession($role);
            $this->asAdmin($token)->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $revision = (int) DB::table('shipping_minimum_point_settings')->value('revision');
            $input = ['minimum_points' => (string) ($revision * 100), 'expected_revision' => $revision];
            $key = (string) Str::uuid7();
            $result = $this->update($token, $input, $key);
            if ($role === V2AdminRole::Operator) { $result->assertForbidden(); continue; }
            $result->assertOk()->assertJsonPath('data.minimum_points', $input['minimum_points'])->assertJsonPath('data.revision', $revision + 1);
            Auth::forgetGuards();
            $this->update($token, $input, $key)->assertOk()->assertJsonPath('idempotent_replay', true);
            Auth::forgetGuards();
            $this->update($token, [...$input, 'minimum_points' => '999'], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
            Auth::forgetGuards();
            $this->update($token, $input)->assertConflict()->assertJsonPath('code', 'SHIPPING_SETTING_REVISION_CONFLICT');
            $audits = DB::table('audit_logs')->where('action_code', 'shipping.settings.updated')->count();
            Auth::forgetGuards();
            $this->update($token, [...$input, 'expected_revision' => $revision + 1])->assertOk()->assertJsonPath('data.revision', $revision + 1);
            self::assertSame($audits, DB::table('audit_logs')->where('action_code', 'shipping.settings.updated')->count());
        }
        Auth::forgetGuards();
        $token = $this->adminSession(V2AdminRole::Owner);
        foreach (['-1', '1.2', '9007199254740992', '01', '1e3', '', 2, null, true, [], ' 1'] as $value) {
            Auth::forgetGuards();
            $this->update($token, ['minimum_points' => $value, 'expected_revision' => 1])->assertUnprocessable();
        }
        Auth::forgetGuards();
        $revision = (int) DB::table('shipping_minimum_point_settings')->value('revision');
        $this->update($token, ['minimum_points' => '9007199254740991', 'expected_revision' => $revision])->assertOk();
        self::assertSame(3, DB::table('audit_logs')->where('action_code', 'shipping.settings.updated')->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        DB::table('shipping_minimum_point_settings')->delete();
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($path)->assertStatus(503)->assertJsonPath('code', 'SHIPPING_POLICY_UNAVAILABLE');
    }

    public function test_database_constraints_reject_invalid_setting_mutations(): void
    {
        foreach ([['minimum_points' => -1], ['minimum_points' => 9007199254740992], ['revision' => 0], ['id' => 2]] as $values) {
            DB::beginTransaction();
            try { DB::table('shipping_minimum_point_settings')->where('id', 1)->update($values); self::fail('Invalid setting must fail'); }
            catch (\Illuminate\Database\QueryException $error) { self::assertSame('23514', $error->getCode()); }
            finally { DB::rollBack(); }
        }
    }

    public function test_csrf_and_missing_idempotency_reject_mutation(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        $this->asAdmin($token)->putJson('/admin/api/v2/settings/shipping-minimum-points', ['minimum_points' => '1', 'expected_revision' => 1])->assertStatus(403);
        Auth::forgetGuards();
        $this->update($token, ['minimum_points' => '1', 'expected_revision' => 1], '')->assertUnprocessable();
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'shipping-minimum-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid shipping admin password'),
            'role' => $role,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $createdAt = now()->subSecond();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now()->subMinutes(6),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]));

        return $token;
    }

    private function update(string $token, array $payload, ?string $key = null)
    {
        $csrf = str_repeat('c', 64);

        return $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ])->putJson('/admin/api/v2/settings/shipping-minimum-points', $payload);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}
