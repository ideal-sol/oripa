<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Reporting\Services\V2AgencyAggregationService;
use App\Models\V2\Agency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgencyAggregationTest extends TestCase
{
    private Agency $agency;
    private int $codeId;
    private string $passwordHash;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32)), 'cache.default' => 'array',
            'v2_agency.mailer' => 'array', 'v2_identity.origins.agency' => 'https://agency.example.test',
            'v2_identity.origins.admin' => 'https://admin.example.test', 'v2_identity.origins.user' => 'https://user.example.test']);
        DB::beginTransaction();
        $this->passwordHash = app(V2AgencyPasswordPolicy::class)->hash('Initial123');
        $this->agency = $this->agency('000001');
        $this->codeId = (int) DB::table('agency_advertising_codes')->where('agency_id', $this->agency->id)->value('id');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_canonical_september_fixture_and_current_sms_classification_move(): void
    {
        $userA = $this->user('2026-08-31 12:00:00+09', true, true);
        $userB = $this->user('2026-09-01 12:00:00+09');
        $userC = $this->user('2026-09-02 12:00:00+09');
        $paymentA = $this->payment($userA, '2026-09-02 12:00:00+09', 3000);
        $this->payment($userA, '2026-09-05 12:00:00+09', 2000);
        $this->payment($userB, '2026-09-03 12:00:00+09', 4000);
        $this->payment($userC, '2026-10-01 12:00:00+09', 6000);
        $this->adjustment($paymentA, 1000);
        $this->assertUsers(2, 0);
        $this->assertSales(1, 4000, 1, 4000);
        self::assertSame(6000, $this->aggregate('sales', ['month' => '2026-10'])['items'][0]['temporary_amount']);
        $history = [DB::table('payments')->get()->toJson(), DB::table('user_advertising_attributions')->get()->toJson(), DB::table('users')->get()->toJson()];
        $this->phone($userB);
        $this->assertUsers(1, 1);
        $this->assertSales(0, 0, 2, 8000);
        self::assertSame($history, [DB::table('payments')->get()->toJson(), DB::table('user_advertising_attributions')->get()->toJson(), DB::table('users')->get()->toJson()]);
    }

    public function test_classification_uses_phone_not_email_or_user_state_for_full_registration(): void
    {
        $this->payment($this->user('2026-09-02', false), '2026-09-02', 1000);
        $this->payment($this->user('2026-09-02', false, true), '2026-09-02', 2000);
        $revoked = $this->user('2026-09-02', true, true);
        DB::table('user_phone_numbers')->where('user_id', $revoked)->update(['revoked_at' => now()]);
        $this->payment($revoked, '2026-09-02', 3000);
        $unverified = $this->user('2026-09-02', true, true);
        DB::table('user_phone_numbers')->where('user_id', $unverified)->update(['verified_at' => null]);
        $this->payment($unverified, '2026-09-02', 4000);
        DB::table('users')->update(['state' => 'suspended']);
        $this->assertUsers(2, 1);
        $this->assertSales(2, 7000, 1, 2000);
    }

    public function test_refund_payers_multiple_refunds_pending_states_and_chargeback(): void
    {
        $fullRefund = $this->payment($this->user('2026-09-02'), '2026-09-02', 3000);
        $this->adjustment($fullRefund, 1000);
        $this->adjustment($fullRefund, 2000);
        $this->assertSales(1, 0, 0, 0);
        $payer = $this->user('2026-08-02', true, true);
        $payment = $this->payment($payer, '2026-09-02', 6000);
        $this->payment($payer, '2026-09-05', 2000);
        $this->adjustment($payment, 1000);
        $this->adjustment($payment, 2000);
        foreach (['requested', 'points_reserved', 'submitted', 'processing', 'failed', 'canceled', 'manual_review'] as $status) {
            $this->adjustment($payment, 500, 'refund', $status);
        }
        $this->adjustment($payment, 1000, 'chargeback');
        $this->adjustment($payment, 1000, 'chargeback_reversal');
        foreach (['created', 'requires_action', 'processing', 'failed', 'canceled', 'expired'] as $status) {
            $this->payment($this->user('2026-09-02'), '2026-09-02', 9000, $status);
        }
        $this->assertSales(1, 0, 1, 5000);
    }

    public function test_tokyo_half_open_boundaries_default_month_ranges_and_leap_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 15:00:00Z'));
        foreach (['2026-08-31 14:59:59Z', '2026-08-31 15:00:00Z', '2026-09-30 14:59:59Z', '2026-09-30 15:00:00Z'] as $date) {
            $this->payment($this->user($date), $date, 1000);
        }
        $this->assertUsers(2, 0);
        $this->assertSales(2, 2000, 0, 0);
        self::assertSame($this->aggregate('users'), $this->aggregate('users', []));
        self::assertSame(['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'timezone' => 'Asia/Tokyo'], $this->aggregate('users', [])['period']);
        $oneDay = ['start_date' => '2026-09-30', 'end_date' => '2026-09-30'];
        $boundary = \App\Domain\Reporting\ValueObjects\V2ReportingPeriod::dateRange('2026-09-30', '2026-09-30');
        self::assertSame('2026-09-29T15:00:00+00:00', $boundary->utcStart()->toIso8601String());
        self::assertSame('2026-09-30T15:00:00+00:00', $boundary->utcEnd()->toIso8601String());
        self::assertSame(1, $this->aggregate('users', $oneDay)['items'][0]['temporary_users']);
        self::assertSame(1000, $this->aggregate('sales', $oneDay)['items'][0]['temporary_amount']);
        foreach (['2024-02-28 15:00:00Z', '2024-02-29 14:59:59Z', '2024-02-29 15:00:00Z'] as $date) {
            $this->payment($this->user($date), $date, 1000);
        }
        $leapDay = ['start_date' => '2024-02-29', 'end_date' => '2024-02-29'];
        self::assertSame(2, $this->aggregate('users', $leapDay)['items'][0]['temporary_users']);
        self::assertSame(2000, $this->aggregate('sales', $leapDay)['items'][0]['temporary_amount']);
    }

    public function test_agency_session_scope_unknown_inputs_privacy_zero_codes_and_pagination(): void
    {
        $other = $this->agency('000002');
        $otherCode = (int) DB::table('agency_advertising_codes')->where('agency_id', $other->id)->value('id');
        $this->payment($this->user('2026-09-02', true, false, $otherCode), '2026-09-02', 9999);
        DB::table('agency_advertising_codes')->insert(['agency_id' => $this->agency->id, 'code' => 'AD000003']);
        $session = $this->realmCookies(V2Realm::Agency, $this->agency->id);
        foreach (['users', 'sales'] as $kind) {
            $path = '/agency/api/v2/aggregates/'.$kind;
            $response = $this->request($path.'?month=2026-09&limit=1', $session)->assertOk()->assertJsonCount(1, 'items');
            $first = $response->json();
            self::assertSame('AD000001', $first['items'][0]['advertising_code']);
            self::assertNotNull($first['next_cursor']);
            self::assertSame(['items', 'period', 'next_cursor'], array_keys($first));
            self::assertSame($kind === 'users' ? ['advertising_code', 'temporary_users', 'full_users']
                : ['advertising_code', 'temporary_paying_users', 'temporary_amount', 'full_paying_users', 'full_amount'], array_keys($first['items'][0]));
            foreach (array_slice($first['items'][0], 1) as $metric) self::assertSame(0, $metric);
            $this->request($path.'?month=2026-09&limit=1&cursor='.urlencode($first['next_cursor']), $session)
                ->assertOk()->assertJsonPath('items.0.advertising_code', 'AD000003')->assertJsonPath('next_cursor', null);
            foreach (['agency_id', 'agencyPublicId', 'advertising_code'] as $field) {
                $this->request($path.'?'.$field.'='.$other->public_id, $session)->assertStatus(422);
                $this->request($path, $session, json_encode([$field => $other->public_id]))->assertStatus(422);
            }
            $this->request($path.'/'.$other->public_id, $session)->assertNotFound();
            foreach (['start_date=2026-09-30&end_date=2026-09-01', 'start_date=2026-09-01', 'end_date=2026-09-30', 'month=2026-13', 'month[]=2026-09', 'start_date=2026-02-29&end_date=2026-03-01', 'month=2026-09&start_date=2026-09-01&end_date=2026-09-02', 'cursor=invalid', 'limit=0', 'limit=101'] as $query) {
                $this->request($path.'?'.$query, $session)->assertStatus(422);
            }
        }
        DB::table('agencies')->where('id', $this->agency->id)->update(['status' => 'suspended']);
        $this->request('/agency/api/v2/aggregates/users', $session)->assertStatus(401);
        $this->request('/agency/api/v2/aggregates/sales', $session)->assertStatus(401);
    }

    public function test_admin_roles_stopped_history_and_both_surface_realm_separation(): void
    {
        $this->payment($this->user('2026-09-02'), '2026-09-02', 1000);
        $agencySession = $this->realmCookies(V2Realm::Agency, $this->agency->id);
        $userSession = $this->realmCookies(V2Realm::User, $this->user('2026-08-02'));
        foreach (['owner', 'admin', 'operator'] as $role) {
            $identity = DB::table('admins')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'email_display' => $role.'@example.test', 'email_normalized' => $role.'@example.test',
                'email_verified_at' => now(), 'password_hash' => $this->passwordHash, 'state' => 'active', 'role' => $role,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $session = $this->realmCookies(V2Realm::Admin, $identity);
            foreach (['users', 'sales'] as $kind) {
                $path = '/admin/api/v2/agencies/aggregates/'.$kind.'?month=2026-09';
                $this->request($path, $session)->assertOk()->assertJsonPath('items.0.company_name', 'QA Company 000001');
                $this->request($path)->assertStatus(401);
                $this->request($path, $agencySession)->assertStatus(401);
                $this->request('/agency/api/v2/aggregates/'.$kind, $session)->assertStatus(403);
                $this->request('/agency/api/v2/aggregates/'.$kind, $userSession)->assertStatus(403);
                $this->request('/agency/api/v2/aggregates/'.$kind)->assertStatus(401);
            }
        }
        DB::table('agencies')->where('id', $this->agency->id)->update(['status' => 'suspended']);
        $this->request('/admin/api/v2/agencies/aggregates/users?month=2026-09', $session)->assertOk()->assertJsonPath('items.0.temporary_users', 1);
        $this->request('/admin/api/v2/agencies/aggregates/sales?month=2026-09', $session)->assertOk()->assertJsonPath('items.0.temporary_amount', 1000);
    }

    public function test_set_based_queries_have_constant_count_and_postgres_execution_plans(): void
    {
        $this->payment($this->user('2026-09-02'), '2026-09-02', 1000);
        for ($offset = 3; $offset < 30; $offset++) {
            DB::table('agency_advertising_codes')->insert(['agency_id' => $this->agency->id, 'code' => 'AD'.str_pad((string) $offset, 6, '0', STR_PAD_LEFT)]);
        }
        foreach (['users', 'sales'] as $kind) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            self::assertCount(28, $this->aggregate($kind)['items']);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertCount(1, $queries);
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings']);
            $details = json_decode($plan[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
            self::assertSame(28, $details['Plan']['Actual Rows']);
            self::assertGreaterThanOrEqual(0, $details['Execution Time']);
            fwrite(STDOUT, "\nAgency {$kind} EXPLAIN: rows=28 execution_ms=".$details['Execution Time']." queries=1\n");
        }
    }

    private function aggregate(string $kind, array $query = ['month' => '2026-09']): array
    {
        return app(V2AgencyAggregationService::class)->agency($this->agency, $kind, $query);
    }

    private function assertUsers(int $temporary, int $full): void
    {
        self::assertSame(['advertising_code' => 'AD000001', 'temporary_users' => $temporary, 'full_users' => $full], $this->aggregate('users')['items'][0]);
    }

    private function assertSales(int $temporaryUsers, int $temporaryAmount, int $fullUsers, int $fullAmount): void
    {
        self::assertSame(['advertising_code' => 'AD000001', 'temporary_paying_users' => $temporaryUsers,
            'temporary_amount' => $temporaryAmount, 'full_paying_users' => $fullUsers, 'full_amount' => $fullAmount], $this->aggregate('sales')['items'][0]);
    }

    private function agency(string $login): Agency
    {
        $identity = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_name' => 'QA Company '.$login, 'contact_name' => 'QA Contact',
            'phone' => '03-1234-5678', 'email' => $login.'@example.test', 'normalized_email' => $login.'@example.test',
            'address' => 'Synthetic address', 'memo' => 'PRIVATE MEMO', 'login_id' => $login,
            'password_hash' => $this->passwordHash, 'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_advertising_codes')->insert(['agency_id' => $identity, 'code' => 'AD'.$login]);

        return Agency::query()->findOrFail($identity);
    }

    private function user(string $created, bool $email = true, bool $sms = false, ?int $codeId = null): int
    {
        $address = Str::uuid7().'@example.test';
        $identity = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'email_display' => $address, 'email_normalized' => $address,
            'email_verified_at' => $email ? now() : null, 'password_hash' => $this->passwordHash,
            'state' => 'active', 'created_at' => $created, 'updated_at' => now(),
        ]);
        DB::table('user_advertising_attributions')->insert(['user_id' => $identity, 'advertising_code_id' => $codeId ?? $this->codeId, 'attributed_at' => now()]);
        if ($sms) $this->phone($identity);

        return $identity;
    }

    private function phone(int $userId): void
    {
        DB::table('user_phone_numbers')->insert(['public_id' => (string) Str::uuid7(), 'user_id' => $userId,
            'phone_ciphertext' => 'isolated-test-ciphertext', 'phone_hmac' => hash('sha256', (string) $userId), 'verified_at' => now()]);
    }

    private function payment(int $userId, string $succeeded, int $amount, string $status = 'succeeded'): int
    {
        return DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'user_id' => $userId, 'provider_code' => 'test',
            'status' => $status, 'amount' => $amount, 'currency' => 'JPY', 'paid_point_amount' => $amount, 'free_point_amount' => 0,
            'plan_name_snapshot' => 'Synthetic Plan', 'plan_code_snapshot' => 'test-plan', 'succeeded_at' => $succeeded,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function adjustment(int $paymentId, int $amount, string $type = 'refund', string $status = 'succeeded'): void
    {
        DB::table('payment_adjustments')->insert(['public_id' => (string) Str::uuid7(), 'payment_id' => $paymentId,
            'type' => $type, 'status' => $status, 'amount' => $amount, 'currency' => 'JPY',
            'requested_at' => '2026-10-10', 'succeeded_at' => $status === 'succeeded' ? '2026-10-10' : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function realmCookies(V2Realm $realm, int $identity): array
    {
        return ['__Host-oripa_'.$realm->value.'_session' => app(V2SessionManager::class)->issue($realm, $identity, true)['token']];
    }

    private function request(string $path, array $cookies = [], ?string $body = null)
    {
        Auth::forgetGuards();

        return $this->call('GET', $path, [], $cookies, [], ['HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $body);
    }
}
