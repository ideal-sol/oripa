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

final class GachaNoticeDefaultsTest extends TestCase
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

    public function test_permissions_initial_rows_and_actor(): void
    {
        $this->getJson('/admin/api/v2/settings/gacha-notices')->assertUnauthorized();
        self::assertSame(2, DB::table('gacha_notice_defaults')->count());
        foreach (V2AdminRole::cases() as $role) {
            $token = $this->adminSession($role);
            Auth::forgetGuards();
            $this->asAdmin($token)->getJson('/admin/api/v2/settings/gacha-notices')
                ->assertOk()->assertJsonPath('data.standard.default_notices', null)
                ->assertJsonPath('data.login.default_notices', null)
                ->assertJsonPath('data.standard.revision', 1)->assertJsonPath('data.login.revision', 1);
        }
        Auth::forgetGuards();
        $this->update($this->adminSession(V2AdminRole::Operator), $this->payload())->assertForbidden();
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            Auth::forgetGuards();
            $revision = (int) DB::table('gacha_notice_defaults')->where('scope', 'standard')->value('revision');
            $token = $this->adminSession($role);
            $this->update($token, $this->payload($revision))->assertOk()
                ->assertJsonPath('data.standard.revision', $revision + 1)
                ->assertJsonPath('data.login.revision', $revision + 1);
            self::assertSame(2, DB::table('gacha_notice_defaults')->whereNotNull('updated_by_admin_id')->count());
            $actor = DB::table('admin_sessions')->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($token))->value('admin_id');
            self::assertSame(2, DB::table('gacha_notice_defaults')->where('updated_by_admin_id', $actor)->count());
        }
    }

    public function test_atomic_occ_idempotency_and_audit_without_gacha_mutation(): void
    {
        app(\App\Domain\Catalog\Services\V2CatalogFixtureImporter::class)->import(json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR
        ));
        $token = $this->adminSession(V2AdminRole::Owner);
        $key = (string) Str::uuid7();
        $before = DB::table('catalog_gacha_versions')->orderBy('id')->get()->toJson();
        self::assertGreaterThan(0, DB::table('catalog_gacha_versions')->count());
        $first = $this->update($token, $this->payload(), $key)->assertOk();
        Auth::forgetGuards();
        $replay = $this->update($token, $this->payload(), $key)->assertOk()
            ->assertJsonPath('idempotent_replay', true)->assertHeader('Idempotency-Replayed', 'true');
        foreach (['standard', 'login'] as $scope) {
            foreach (['default_notices', 'revision'] as $field) {
                self::assertSame($first->json('data.'.$scope.'.'.$field), $replay->json('data.'.$scope.'.'.$field));
            }
        }
        foreach (['standard', 'login'] as $staleScope) {
            $payload = $this->payload(2);
            $payload[$staleScope]['expected_revision'] = 1;
            $payload['standard']['default_notices'] = 'Should not persist';
            $payload['login']['default_notices'] = 'Should not persist';
            Auth::forgetGuards();
            $this->update($token, $payload)->assertConflict()
                ->assertJsonPath('code', 'GACHA_NOTICE_DEFAULT_REVISION_CONFLICT');
            self::assertSame(2, DB::table('gacha_notice_defaults')->where('revision', 2)->count());
            self::assertSame(0, DB::table('gacha_notice_defaults')->where('default_notices', 'Should not persist')->count());
        }
        Auth::forgetGuards();
        $this->update($token, $this->payload(2), $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        self::assertSame(2, DB::table('audit_logs')->where('action_code', 'catalog.gacha_notice_default.updated')->count());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'catalog.gacha_notice_default.updated')->count());
        self::assertSame($before, DB::table('catalog_gacha_versions')->orderBy('id')->get()->toJson());
        Auth::forgetGuards();
        $this->update($token, $this->payload(2))->assertOk()->assertJsonPath('data.standard.revision', 3);
    }

    public function test_text_normalization_and_exact_input(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        foreach ([str_repeat('あ', 10001), '<b>HTML</b>', "bad\0text", 123] as $invalid) {
            $payload = $this->payload();
            $payload['login']['default_notices'] = $invalid;
            Auth::forgetGuards();
            $this->update($token, $payload)->assertUnprocessable();
        }
        foreach (['root', 'row', 'missing', 'revision'] as $case) {
            $payload = $this->payload();
            if ($case === 'root') $payload['extra'] = true;
            if ($case === 'row') $payload['login']['extra'] = true;
            if ($case === 'missing') unset($payload['login']['default_notices']);
            if ($case === 'revision') $payload['login']['expected_revision'] = '1';
            Auth::forgetGuards();
            $this->update($token, $payload)->assertUnprocessable();
        }
        $payload = $this->payload();
        $payload['standard']['default_notices'] = "  e\u{0301}  ";
        $payload['login']['default_notices'] = " \n\t ";
        Auth::forgetGuards();
        $this->update($token, $payload)->assertOk()->assertJsonPath('data.standard.default_notices', 'é')
            ->assertJsonPath('data.login.default_notices', null);
        $payload = $this->payload(2);
        $payload['standard']['default_notices'] = str_repeat('あ', 10000);
        Auth::forgetGuards();
        $this->update($token, $payload)->assertOk();
    }

    public function test_database_constraints_reject_invalid_rows(): void
    {
        foreach ([
            ['scope' => 'signup'], ['scope' => 'standard'], ['scope' => 'login', 'revision' => 0],
            ['scope' => 'login', 'updated_by_admin_id' => 9223372036854775807],
        ] as $values) {
            DB::beginTransaction();
            try {
                if ($values['scope'] === 'login') {
                    DB::table('gacha_notice_defaults')->where('scope', 'login')->update($values);
                } else {
                    DB::table('gacha_notice_defaults')->insert(['public_id' => (string) Str::uuid7(), ...$values]);
                }
                self::fail('Database must reject invalid settings.');
            } catch (\Illuminate\Database\QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_second_scope_outbox_failure_rolls_back_both_settings_audit_and_idempotency(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_notice_outbox_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.event_type = 'catalog.gacha_notice_default.updated' AND NEW.payload->>'scope' = 'login' THEN
                    RAISE EXCEPTION 'synthetic notice outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER v2_test_notice_outbox_failure BEFORE INSERT ON outbox_messages FOR EACH ROW EXECUTE FUNCTION v2_test_notice_outbox_failure()');
        $token = $this->adminSession(V2AdminRole::Owner);
        $key = (string) Str::uuid7();
        $this->withoutExceptionHandling();
        try {
            $this->update($token, $this->payload(), $key);
            self::fail('Outbox failure must roll back the aggregate.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('synthetic notice outbox failure', $exception->getMessage());
        }
        self::assertSame(2, DB::table('gacha_notice_defaults')->where('revision', 1)->whereNull('default_notices')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action_code', 'catalog.gacha_notice_default.updated')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'catalog.gacha_notice_default.updated')->count());
        DB::statement('DROP TRIGGER v2_test_notice_outbox_failure ON outbox_messages');
        DB::statement('DROP FUNCTION v2_test_notice_outbox_failure()');
        Auth::forgetGuards();
        $this->update($token, $this->payload(), $key)->assertOk()->assertJsonPath('idempotent_replay', false);
    }

    private function payload(int $revision = 1): array
    {
        return [
            'standard' => ['default_notices' => 'Standard QA notice', 'expected_revision' => $revision],
            'login' => ['default_notices' => 'Login QA notice', 'expected_revision' => $revision],
        ];
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'referral-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid referral admin password'),
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
            ])->putJson('/admin/api/v2/settings/gacha-notices', $payload);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}
