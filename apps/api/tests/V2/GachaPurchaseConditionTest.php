<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogReadService;
use App\Domain\Catalog\Services\V2GachaCompositionService;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Catalog\Services\V2GachaPurchaseCondition;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class GachaPurchaseConditionTest extends TestCase
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

    public static function boundaries(): array
    {
        return [
            ['purchase_count', 'eq', 0, 0, true], ['purchase_count', 'eq', 0, 1, false],
            ['purchase_count', 'gte', 3, 2, false], ['purchase_count', 'gte', 3, 3, true],
            ['purchase_count', 'lt', 3, 2, true], ['purchase_count', 'lt', 3, 3, false],
            ['purchase_amount', 'gte', 10000, 9999, false], ['purchase_amount', 'gte', 10000, 10000, true],
            ['purchase_amount', 'eq', 10000, 9999, false], ['purchase_amount', 'eq', 10000, 10000, true],
            ['purchase_amount', 'eq', 10000, 10001, false], ['purchase_amount', 'lt', 10000, 9999, true],
            ['purchase_amount', 'lt', 10000, 10000, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_real_payment_boundaries_presentation_and_draw(string $field, string $mode, int $threshold, int $actual, bool $eligible): void
    {
        $user = $this->fundedRankUser(1);
        if ($field === 'purchase_count') {
            for ($count = 0; $count < $actual; $count++) {
                $this->settleRankPayment($user, 100);
            }
        } elseif ($actual > 0) {
            $this->settleRankPayment($user, $actual);
        }
        $gacha = $this->rankGacha(overrides: [$field.'_mode' => $mode, $field.'_value' => $threshold]);
        $state = app(V2CatalogReadService::class)->presentationState($gacha->public_id, $user);
        self::assertSame($eligible, $state['eligible']);
        self::assertSame($actual, $state['purchase_progress'][$field]);
        self::assertSame(['operator' => $mode, 'value' => $threshold], $state['conditions'][$field]);
        if (! $eligible) {
            self::assertSame($field.'_not_eligible', $state['ineligible_reason']);
            try {
                $this->rankDraw($user, $gacha);
                self::fail('An ineligible purchase history must not draw.');
            } catch (V2DrawException $error) {
                self::assertSame('GACHA_'.strtoupper($field).'_NOT_ELIGIBLE', $error->errorCode);
            }
        } else {
            $this->rankDraw($user, $gacha);
            $snapshot = DB::table('draw_requests')->where('user_id', $user->id)->sole();
            self::assertSame($actual, (int) $snapshot->{$field.'_snapshot'});
        }
    }

    public function test_progress_updates_once_per_list_and_unlimited_draws_preserve_idempotency(): void
    {
        $user = $this->fundedRankUser(1);
        $gacha = $this->rankGacha(overrides: ['purchase_count_mode' => 'gte', 'purchase_count_value' => 3,
            'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 10000]);
        $other = $this->rankGacha(overrides: ['purchase_count_mode' => 'eq', 'purchase_count_value' => 2]);
        $this->settleRankPayment($user, 4000);
        $this->settleRankPayment($user, 4000, 1000);
        $catalog = app(V2CatalogReadService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $list = $catalog->list(100, null, null, null, $user);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS purchase_count')));
        $state = collect($list['data'])->firstWhere('id', $gacha->public_id)['presentation'];
        self::assertSame(['purchase_count' => 2, 'purchase_amount' => 8000,
            'purchase_count_remaining' => 1, 'purchase_amount_remaining' => 2000], $state['purchase_progress']);
        self::assertTrue(collect($list['data'])->firstWhere('id', $other->public_id)['presentation']['eligible']);
        self::assertNull($catalog->presentationState($gacha->public_id, null)['purchase_progress']);
        $this->settleRankPayment($user, 2000);
        $first = $this->rankDraw($user, $gacha, 'g1-replay');
        $replay = $this->rankDraw($user, $gacha, 'g1-replay');
        self::assertTrue($replay['idempotent_replay']);
        $first['idempotent_replay'] = true;
        self::assertEquals($first, $replay);
        $this->rankDraw($user, $gacha);
        self::assertSame(2, DB::table('draw_requests')->where('user_id', $user->id)->count());
        $state = $catalog->presentationState($gacha->public_id, $user);
        self::assertNull($state['purchase_progress']['purchase_count_remaining']);
        self::assertNull($state['purchase_progress']['purchase_amount_remaining']);
        self::assertSame(3, $state['purchase_progress']['purchase_count']);
        self::assertFalse($catalog->presentationState($other->public_id, $user)['eligible']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_pending_payments_and_pending_refunds_only_successful_adjustments_are_excluded(): void
    {
        $user = $this->fundedRankUser(1);
        $this->rankPayment($user, 500);
        self::assertSame(['purchase_count' => 0, 'purchase_amount' => 0], V2GachaPurchaseCondition::aggregate($user->id));
        $payment = $this->settleRankPayment($user, 1000, 100);
        $context = $this->rankAdmin();
        $adjustments = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $adjustments->write($context, $payment->public_id, 'refund', ['reason' => 'Synthetic G1 refund'], (string) Str::uuid7());
        self::assertSame(['purchase_count' => 1, 'purchase_amount' => 1000], V2GachaPurchaseCondition::aggregate($user->id));
        $adjustments->write($context, $payment->public_id, 'complete',
            ['reason' => 'Synthetic G1 refund success', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame(['purchase_count' => 0, 'purchase_amount' => 0], V2GachaPurchaseCondition::aggregate($user->id));
        $payment = $this->settleRankPayment($user, 2000);
        $adjustments->write($context, $payment->public_id, 'chargeback', ['reason' => 'Synthetic G1 chargeback'], (string) Str::uuid7());
        self::assertSame(['purchase_count' => 0, 'purchase_amount' => 0], V2GachaPurchaseCondition::aggregate($user->id));
        $parent = DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('type', 'chargeback')->sole();
        $payments = app(V2PaymentService::class);
        $event = $payments->recordVerifiedProviderEvent('fincode', 'g1-'.Str::uuid7(), 'payment.chargeback_reversed', '{}', [], $payment->id);
        $payments->recordChargebackReversal($event->id, $parent->id);
        self::assertSame(['purchase_count' => 0, 'purchase_amount' => 0], V2GachaPurchaseCondition::aggregate($user->id));
    }

    public function test_copy_defaults_and_reason_priority(): void
    {
        $user = $this->fundedRankUser(1);
        $conditions = ['purchase_count_mode' => 'gte', 'purchase_count_value' => 3,
            'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 10000];
        $gacha = $this->rankGacha(overrides: $conditions);
        $copy = app(V2GachaCopyService::class)->projection($gacha->public_id, true);
        self::assertSame($conditions, array_intersect_key($copy, $conditions));
        $state = app(V2CatalogReadService::class)->presentationState($gacha->public_id, $user);
        self::assertSame('purchase_count_not_eligible', $state['ineligible_reason']);
        $ranked = $this->rankGacha('standard', 'only', 1, $conditions);
        self::assertSame('member_rank_not_eligible', app(V2CatalogReadService::class)->presentationState($ranked->public_id, $user)['ineligible_reason']);
        $audience = $this->rankGacha('standard', 'only', 1, [...$conditions, 'audience_code' => 'first_time_users']);
        self::assertSame('audience_not_eligible', app(V2CatalogReadService::class)->presentationState($audience->public_id, $user)['ineligible_reason']);
        $fixture = DB::table('catalog_gacha_versions')->where('public_id', '0198a001-0000-7000-8000-000000000012')->first();
        self::assertSame('none', $fixture->purchase_count_mode);
        self::assertNull($fixture->purchase_amount_value);
    }

    public function test_database_guards_preserve_published_conditions_and_draw_snapshots(): void
    {
        $user = $this->fundedRankUser(1);
        $gacha = $this->rankGacha(overrides: ['purchase_count_mode' => 'eq', 'purchase_count_value' => 0]);
        $draw = $this->rankDraw($user, $gacha);
        $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('id', $gacha->published_version_id)
            ->update(['purchase_count_mode' => 'none', 'purchase_count_value' => null]));
        foreach ([['purchase_count_snapshot', 1], ['purchase_amount_snapshot', 1], ['purchase_count_snapshot', null]] as [$field, $value]) {
            $this->rejectSql(fn () => DB::table('draw_requests')->where('public_id', $draw['id'])->update([$field => $value]));
        }
        $this->rejectSql(fn () => DB::table('draw_requests')->where('public_id', $draw['id'])->delete());
        $service = app(V2GachaCompositionService::class);
        $draft = $service->save($service->validate($this->rankGachaInput()), null, null, $this->rankAdmin());
        foreach ([
            ['purchase_count_mode' => 'eq'], ['purchase_count_value' => 1],
            ['purchase_count_mode' => 'gte', 'purchase_count_value' => 0],
            ['purchase_amount_mode' => 'lt', 'purchase_amount_value' => 0],
            ['purchase_count_mode' => 'eq', 'purchase_count_value' => 0, 'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1],
        ] as $invalid) {
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('gacha_id', $draft->id)->update($invalid));
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_http_purchase_problem_codes_and_cache_separation(): void
    {
        $user = $this->fundedRankUser(1);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        foreach (['purchase_count', 'purchase_amount'] as $field) {
            $gacha = $this->rankGacha(overrides: [$field.'_mode' => 'gte', $field.'_value' => 1]);
            $this->getJson('/api/v2/gachas/'.$gacha->public_id)->assertOk()
                ->assertJsonPath('data.presentation.conditions.'.$field.'.value', 1)
                ->assertJsonPath('data.presentation.purchase_progress', null);
            Auth::guard('v2_user')->setUser($user);
            $this->getJson('/api/v2/gachas')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $csrf = str_repeat('b', 64);
            $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
                ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
                ->withHeaders(['Origin' => 'https://storefront.example.test', 'X-XSRF-TOKEN' => $csrf,
                    'Sec-Fetch-Site' => 'same-origin', 'Idempotency-Key' => 'g1-http-'.$field])
                ->postJson('/api/v2/gachas/'.$gacha->public_id.'/draws', ['draw_count' => 1])
                ->assertForbidden()->assertJsonPath('code', 'GACHA_'.strtoupper($field).'_NOT_ELIGIBLE');
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

    public function test_contradictory_and_signup_purchase_conditions_are_rejected(): void
    {
        $service = app(V2GachaCompositionService::class);
        foreach ([
            ['purchase_count_mode' => 'eq', 'purchase_count_value' => 0, 'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1],
            ['purchase_amount_mode' => 'lt', 'purchase_amount_value' => 1, 'purchase_count_mode' => 'eq', 'purchase_count_value' => 1],
            ['purchase_count_mode' => 'gte', 'purchase_count_value' => 0],
            ['purchase_count_mode' => 'eq', 'purchase_count_value' => 1000001],
        ] as $invalid) {
            try {
                $service->validate([...$this->rankGachaInput(), ...$invalid]);
                self::fail('Invalid purchase condition accepted.');
            } catch (V2CatalogException $error) {
                self::assertSame('CATALOG_MUTATION_INVALID', $error->errorCode);
            }
        }
        $this->expectException(V2CatalogException::class);
        $service->validate([...$this->rankGachaInput('signup_once'), 'purchase_count_mode' => 'eq', 'purchase_count_value' => 0]);
    }
}
