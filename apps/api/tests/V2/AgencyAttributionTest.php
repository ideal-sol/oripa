<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Services\V2AgencyAttributionService;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Domain\Reporting\Services\V2AgencyAggregationService;
use App\Models\V2\Agency;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class AgencyAttributionTest extends TestCase
{
    private Agency $agency;
    private int $codeId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'v2_identity.origins.user' => 'https://storefront.example.test']);
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send')->byDefault();
        DB::beginTransaction();
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_name' => 'Synthetic Agency', 'contact_name' => 'QA',
            'phone' => '0311112222', 'email' => 'attribution@example.test', 'normalized_email' => 'attribution@example.test',
            'address' => 'Synthetic', 'login_id' => '000001',
            'password_hash' => app(V2AgencyPasswordPolicy::class)->hash('Initial123'),
            'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agency = Agency::findOrFail($agencyId);
        $this->codeId = DB::table('agency_advertising_codes')->insertGetId(['agency_id' => $agencyId, 'code' => 'Ab12Cd34']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public static function candidates(): array
    {
        return [
            'active' => ['Ab12Cd34', true], 'absent' => [null, false],
            'short' => ['bad', false], 'long' => [str_repeat('A', 100), false],
            'nonexistent' => ['Xx00Yy99', false], 'case' => ['ab12cd34', false],
            'space' => [' Ab12Cd34', false], 'newline' => ["Ab12Cd34\n", false],
            'unicode' => ['Ａb12Cd34', false], 'array' => [['Ab12Cd34'], false],
        ];
    }

    #[DataProvider('candidates')]
    public function test_registration_candidates_do_not_block_registration(mixed $candidate, bool $valid): void
    {
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send')->once();
        $user = $this->register($candidate);
        self::assertNotNull($user->getKey());
        self::assertDatabaseHas('user_email_verifications', ['user_id' => $user->id]);
        self::assertSame($valid ? 1 : 0, DB::table('user_advertising_attributions')->where('user_id', $user->id)->count());
        if ($valid) self::assertDatabaseHas('user_advertising_attributions', ['user_id' => $user->id, 'advertising_code_id' => $this->codeId]);
    }

    #[DataProvider('candidates')]
    public function test_public_validation_only_returns_validity(mixed $candidate, bool $valid): void
    {
        $this->getJson('/api/v2/advertising-code-validation?'.http_build_query(['advertising_code' => $candidate]))
            ->assertOk()->assertExactJson(['valid' => $valid])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_suspension_after_click_is_rechecked_inside_registration(): void
    {
        self::assertTrue(app(V2AgencyAttributionService::class)->isValid('Ab12Cd34'));
        $this->agency->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/v2/advertising-code-validation?advertising_code=Ab12Cd34')->assertExactJson(['valid' => false]);
        $user = $this->register('Ab12Cd34');
        self::assertDatabaseMissing('user_advertising_attributions', ['user_id' => $user->id]);
    }

    public function test_existing_attribution_is_not_overwritten_and_reloaded_users_are_not_backfilled(): void
    {
        DB::table('agency_advertising_codes')->insert(['agency_id' => $this->agency->id, 'code' => 'Zz12Yy34']);
        $user = $this->register('Ab12Cd34');
        $before = DB::table('user_advertising_attributions')->where('user_id', $user->id)->first();
        app(V2AgencyAttributionService::class)->attributeNewUserFromAdvertisingCode($user, 'Zz12Yy34');
        self::assertEquals($before, DB::table('user_advertising_attributions')->where('user_id', $user->id)->first());
        $other = $this->register(null);
        app(V2AgencyAttributionService::class)->attributeNewUserFromAdvertisingCode($other->fresh(), 'Ab12Cd34');
        self::assertDatabaseMissing('user_advertising_attributions', ['user_id' => $other->id]);
        $this->postJson('/api/v2/me/advertising-attribution', ['advertising_code' => 'Ab12Cd34'])->assertNotFound();
    }

    public function test_failure_after_attribution_rolls_back_user_and_attribution_together(): void
    {
        $users = User::count();
        $attributions = DB::table('user_advertising_attributions')->count();
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send')->once()->andReturnUsing(function (User $user): void {
            self::assertGreaterThan(1, DB::transactionLevel());
            self::assertDatabaseHas('user_advertising_attributions', ['user_id' => $user->id]);
            throw new RuntimeException('Synthetic post-attribution failure');
        });
        try {
            $this->register('Ab12Cd34');
            self::fail('Expected rollback');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic post-attribution failure', $exception->getMessage());
        }
        self::assertSame($users, User::count());
        self::assertSame($attributions, DB::table('user_advertising_attributions')->count());
    }

    public function test_http_registration_optional_candidate_preserves_response_and_security(): void
    {
        foreach ([[], ['advertising_code' => 'Ab12Cd34'], ['advertising_code' => ['invalid']], ['advertising_code' => ' Ab12Cd34']] as $extra) {
            $session = $this->getJson('/api/v2/auth/session');
            $csrf = collect($session->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === '__Host-oripa_user_xsrf')->getValue();
            $response = $this->call('POST', '/api/v2/auth/register', [], ['__Host-oripa_user_xsrf' => $csrf], [], [
                'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://storefront.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_XSRF_TOKEN' => $csrf,
            ], json_encode(['email' => Str::uuid7().'@example.test', 'password' => 'valid user password', ...$extra]));
            $response->assertStatus(202);
            self::assertSame(['status', 'user_id'], array_keys($response->json()));
            $user = User::where('public_id', $response->json('user_id'))->sole();
            self::assertSame(($extra['advertising_code'] ?? null) === 'Ab12Cd34' ? 1 : 0,
                DB::table('user_advertising_attributions')->where('user_id', $user->id)->count());
        }
        $this->postJson('/api/v2/auth/register', ['email' => 'missing-security@example.test', 'password' => 'valid password'])->assertForbidden();
    }

    public function test_registration_connects_to_existing_user_and_sales_aggregates(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-08T12:00:00Z'));
        $user = $this->register('Ab12Cd34');
        $aggregate = fn (string $kind) => app(V2AgencyAggregationService::class)->agency($this->agency, $kind, ['month' => '2026-09'])['items'][0];
        self::assertSame(0, $aggregate('users')['temporary_users']);
        $user->update(['email_verified_at' => now(), 'state' => 'active']);
        self::assertSame(1, $aggregate('users')['temporary_users']);
        DB::table('payments')->insert([
            'public_id' => (string) Str::uuid7(), 'user_id' => $user->id, 'provider_code' => 'test',
            'status' => 'succeeded', 'amount' => 3000, 'currency' => 'JPY', 'paid_point_amount' => 3000, 'free_point_amount' => 0,
            'plan_name_snapshot' => 'Synthetic Plan', 'plan_code_snapshot' => 'test-plan', 'succeeded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame(3000, $aggregate('sales')['temporary_amount']);
        DB::table('user_phone_numbers')->insert(['public_id' => (string) Str::uuid7(), 'user_id' => $user->id,
            'phone_ciphertext' => 'isolated-test-ciphertext', 'phone_hmac' => hash('sha256', (string) $user->id), 'verified_at' => now()]);
        self::assertSame(0, $aggregate('users')['temporary_users']);
        self::assertSame(1, $aggregate('users')['full_users']);
        self::assertSame(1, $aggregate('sales')['full_paying_users']);
        self::assertSame(3000, $aggregate('sales')['full_amount']);
        self::assertSame(0, $aggregate('sales')['temporary_amount']);
    }

    private function register(mixed $code): User
    {
        return app(V2UserAuthenticationService::class)->register(Str::uuid7().'@example.test', 'valid user password', '/', '192.0.2.'.random_int(1, 254), $code);
    }

    public function test_candidate_migration_rolls_back_and_reapplies_without_changing_identity_guards(): void
    {
        $migration = require database_path('migrations-v2/2026_10_01_000075_add_v2_external_identity_advertising_candidate.php');
        $migration->down();
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('external_identity_transactions', 'advertising_code_candidate'));
        $migration->up();
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('external_identity_transactions', 'advertising_code_candidate'));
        self::assertTrue(DB::table('pg_trigger')->where('tgname', 'external_identity_transactions_guard_update')->exists());
        self::assertTrue(DB::table('pg_trigger')->where('tgname', 'user_advertising_attributions_immutable')->exists());
    }
}
