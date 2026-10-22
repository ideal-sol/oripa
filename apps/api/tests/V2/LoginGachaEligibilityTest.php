<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2GachaCompositionService;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Catalog\Services\V2LoginCatalogReadService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawEligibilityService;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class LoginGachaEligibilityTest extends TestCase
{
    use MemberRankGachaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
        $this->importRankGachas();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public static function rankModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('rankModes')]
    public function test_three_to_five_purchases_each_gacha_daily_jst_and_replay(bool $rankEnabled): void
    {
        if ($rankEnabled) {
            $this->enableRankFixture();
        }
        $user = $this->fundedRankUser(1);
        $gachas = [];
        foreach ([['gte', 3], ['gte', 5], ['eq', 3], ['eq', 5]] as [$mode, $value]) {
            $gachas[] = $this->rankGacha('login_daily', overrides: ['purchase_count_mode' => $mode, 'purchase_count_value' => $value]);
        }
        for ($count = 0; $count < 3; $count++) {
            $this->settleRankPayment($user, 100);
        }
        $catalog = app(V2LoginCatalogReadService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $items = collect($catalog->listing($user)['items']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS purchase_count')));
        foreach ([true, false, true, false] as $index => $eligible) {
            self::assertSame($eligible, $items->firstWhere('id', $gachas[$index]->public_id)['eligibility']['eligible']);
        }
        $first = $this->rankDraw($user, $gachas[0], 'g2-replay');
        self::assertTrue($this->rankDraw($user, $gachas[0], 'g2-replay')['idempotent_replay']);
        $version = DB::table('catalog_gacha_versions')->find($gachas[0]->published_version_id);
        $version->id = $version->id + 100000;
        self::assertSame('already_used', app(V2DrawEligibilityService::class)->evaluateLogin($user, $gachas[0], $version, CarbonImmutable::now())['reason']);
        $this->rankDraw($user, $gachas[2]);
        $this->denied($user, $gachas[1], 'GACHA_PURCHASE_COUNT_NOT_ELIGIBLE');
        $this->settleRankPayment($user, 100);
        $this->settleRankPayment($user, 100);
        $this->rankDraw($user, $gachas[1]);
        $this->rankDraw($user, $gachas[3]);
        $this->denied($user, $gachas[0], 'DAILY_DRAW_LIMIT_EXCEEDED');
        $this->denied($user, $gachas[2], 'GACHA_PURCHASE_COUNT_NOT_ELIGIBLE');
        self::assertSame(3, (int) DB::table('draw_requests')->where('public_id', $first['id'])->value('purchase_count_snapshot'));
        self::assertSame(4, DB::table('draw_requests')->where('user_id', $user->id)->count());
        $this->rankClock('2026-10-31T14:59:59Z');
        $this->denied($user, $gachas[0], 'DAILY_DRAW_LIMIT_EXCEEDED');
        $this->rankClock('2026-10-31T15:00:00Z');
        foreach ([0, 1, 3] as $index) {
            $this->rankDraw($user, $gachas[$index]);
            $this->denied($user, $gachas[$index], 'DAILY_DRAW_LIMIT_EXCEEDED');
        }
        $this->denied($user, $gachas[2], 'GACHA_PURCHASE_COUNT_NOT_ELIGIBLE');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProviderExternal(GachaPurchaseConditionTest::class, 'boundaries')]
    public function test_login_purchase_boundaries_and_snapshots(string $field, string $mode, int $threshold, int $actual, bool $eligible): void
    {
        $user = $this->fundedRankUser(1);
        if ($field === 'purchase_count') {
            for ($count = 0; $count < $actual; $count++) {
                $this->settleRankPayment($user, 100);
            }
        } elseif ($actual > 0) {
            $this->settleRankPayment($user, $actual);
        }
        $gacha = $this->rankGacha('login_daily', overrides: [$field.'_mode' => $mode, $field.'_value' => $threshold]);
        $data = app(V2LoginCatalogReadService::class)->detail($gacha->public_id, $user)['data'];
        self::assertSame($eligible, $data['eligibility']['eligible']);
        self::assertSame($actual, $data['eligibility']['purchase_progress'][$field]);
        self::assertSame($mode === 'gte' && ! $eligible ? $threshold - $actual : null, $data['eligibility']['purchase_progress'][$field.'_remaining']);
        if (! $eligible) {
            $this->denied($user, $gacha, 'GACHA_'.strtoupper($field).'_NOT_ELIGIBLE');
        } else {
            $draw = $this->rankDraw($user, $gacha);
            self::assertSame($actual, (int) DB::table('draw_requests')->where('public_id', $draw['id'])->value($field.'_snapshot'));
            $this->rejectSql(fn () => DB::table('draw_requests')->where('public_id', $draw['id'])->update([$field.'_snapshot' => $actual + 1]));
        }
    }

    public function test_and_priority_audience_line_rank_and_purchase(): void
    {
        $user = $this->fundedRankUser(1);
        $gacha = $this->rankGacha('login_daily', 'only', 1, ['audience_code' => 'first_time_users', 'first_time_eligible_days' => 1,
            'purchase_count_mode' => 'gte', 'purchase_count_value' => 1, 'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1000]);
        $version = DB::table('catalog_gacha_versions')->find($gacha->published_version_id);
        $service = app(V2DrawEligibilityService::class);
        $instant = CarbonImmutable::now();
        self::assertSame('authentication_required', $service->evaluateLogin(null, $gacha, $version, $instant)['reason']);
        self::assertSame('audience_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        $version->audience_code = 'line_users';
        self::assertSame('audience_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        $version->audience_code = 'first_time_users';
        $user->created_at = $instant->subDay();
        self::assertSame('member_rank_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        $this->enableRankFixture();
        self::assertSame('purchase_count_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        $this->settleRankPayment($user, 100);
        self::assertSame('purchase_amount_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        $this->settleRankPayment($user, 900);
        self::assertTrue($service->evaluateLogin($user, $gacha, $version, $instant)['eligible']);
        self::assertSame('audience_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant->addSecond())['reason']);
        $version->audience_code = 'line_users';
        $subjectHash = hash('sha256', 'g2-synthetic-line-user');
        DB::table('external_identity_accounts')->insert([
            'public_id' => (string) Str::uuid7(), 'user_id' => $user->id, 'provider' => 'line',
            'issuer' => 'https://access.line.me', 'subject_hash' => $subjectHash, 'linked_at' => now(),
            'last_authenticated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame('audience_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
        DB::table('line_friendships')->insert([
            'public_id' => (string) Str::uuid7(), 'subject_hash' => $subjectHash, 'user_id' => $user->id,
            'status' => 'friend', 'followed_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertTrue($service->evaluateLogin($user, $gacha, $version, $instant)['eligible']);
        DB::table('line_friendships')->where('user_id', $user->id)->update(['status' => 'unfollowed', 'unfollowed_at' => now()]);
        self::assertSame('audience_not_eligible', $service->evaluateLogin($user, $gacha, $version, $instant)['reason']);
    }

    public function test_copy_signup_fixed_fields_and_database_guards(): void
    {
        $fields = ['audience_code' => 'line_users', 'first_time_eligible_days' => 365,
            'purchase_count_mode' => 'gte', 'purchase_count_value' => 3, 'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1000];
        $gacha = $this->rankGacha('login_daily', 'only', 2, $fields);
        $copy = app(V2GachaCopyService::class)->projection($gacha->public_id, true);
        foreach ($fields as $field => $value) {
            self::assertSame($value, $copy[$field]);
        }
        $copy['publish_start_at'] = CarbonImmutable::now()->toIso8601ZuluString();
        $service = app(V2GachaCompositionService::class);
        $draft = $service->save($service->validate($copy), null, null, $this->rankAdmin());
        foreach ([0, 366] as $days) {
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('gacha_id', $draft->id)->update(['first_time_eligible_days' => $days]));
        }
        $signup = $service->save($service->validate($this->rankGachaInput('signup_once')), null, null, $this->rankAdmin());
        foreach ([['audience_code' => 'line_users'], ['first_time_eligible_days' => 365],
            ['member_rank_mode' => 'only', 'member_rank_level' => 2],
            ['purchase_count_mode' => 'eq', 'purchase_count_value' => 0],
            ['purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1]] as $invalid) {
            try {
                $service->validate([...$this->rankGachaInput('signup_once'), ...$invalid]);
                self::fail('Signup fixed field accepted.');
            } catch (V2CatalogException $error) {
                self::assertSame(422, $error->status);
            }
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('gacha_id', $signup->id)->update($invalid));
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('id', $gacha->published_version_id)->update($invalid));
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_chargeback_reversal_and_private_http_contract(): void
    {
        $user = $this->fundedRankUser(1);
        $gacha = $this->rankGacha('login_daily', overrides: ['purchase_count_mode' => 'gte', 'purchase_count_value' => 1]);
        $this->getJson('/api/v2/login-gachas')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie');
        $anonymous = collect(app(V2LoginCatalogReadService::class)->listing()['items'])->firstWhere('id', $gacha->public_id);
        self::assertSame(['operator' => 'gte', 'value' => 1], $anonymous['conditions']['purchase_count']);
        self::assertNull($anonymous['eligibility']['purchase_progress']);
        Auth::guard('v2_user')->setUser($user);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $csrf = str_repeat('b', 64);
        foreach (['purchase_count', 'purchase_amount'] as $field) {
            $target = $this->rankGacha('login_daily', overrides: [$field.'_mode' => 'gte', $field.'_value' => 1]);
            $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
                ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
                ->withHeaders(['Origin' => 'https://storefront.example.test', 'X-XSRF-TOKEN' => $csrf, 'Sec-Fetch-Site' => 'same-origin', 'Idempotency-Key' => 'g2-http-'.$field])
                ->postJson('/api/v2/gachas/'.$target->public_id.'/draws', ['draw_count' => 1])
                ->assertForbidden()->assertJsonPath('code', 'GACHA_'.strtoupper($field).'_NOT_ELIGIBLE');
        }
        $payment = $this->settleRankPayment($user, 1000);
        $this->getJson('/api/v2/login-gachas/'.$gacha->public_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'Cookie')->assertJsonPath('data.eligibility.purchase_progress.purchase_count', 1);
        $adjustments = app(V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $pending = $adjustments->write($context, $payment->public_id, 'refund', ['reason' => 'Synthetic G2 refund'], (string) Str::uuid7());
        self::assertTrue(app(V2LoginCatalogReadService::class)->detail($gacha->public_id, $user)['data']['eligibility']['eligible']);
        $adjustments->write($context, $payment->public_id, 'complete', ['reason' => 'Synthetic G2 refund', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $pending['data']['id']);
        $this->denied($user, $gacha, 'GACHA_PURCHASE_COUNT_NOT_ELIGIBLE');
        $payment = $this->settleRankPayment($user, 2000);
        $adjustments->write($context, $payment->public_id, 'chargeback', ['reason' => 'Synthetic G2 chargeback'], (string) Str::uuid7());
        $parent = DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('type', 'chargeback')->sole();
        $payments = app(V2PaymentService::class);
        $event = $payments->recordVerifiedProviderEvent('fincode', 'g2-'.Str::uuid7(), 'payment.chargeback_reversed', '{}', [], $payment->id);
        $payments->recordChargebackReversal($event->id, $parent->id);
        $this->denied($user, $gacha, 'GACHA_PURCHASE_COUNT_NOT_ELIGIBLE');
    }

    private function denied(object $user, object $gacha, string $code): void
    {
        try {
            $this->rankDraw($user, $gacha);
            self::fail('Expected Draw rejection.');
        } catch (V2DrawException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    private function rejectSql(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
            self::fail('Invalid SQL mutation accepted.');
        } catch (QueryException $error) {
            self::assertContains($error->errorInfo[0], ['P0001', '23514']);
        }
    }
}
