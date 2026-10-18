<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogReadService;
use App\Domain\Catalog\Services\V2GachaCompositionService;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Catalog\Services\V2LoginCatalogReadService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\MemberRank\Services\V2MemberRankGachaCondition;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class MemberRankGachaTest extends TestCase
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
        Auth::forgetGuards();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function conditions(): array
    {
        return [['at_least', 4, 3, false], ['at_least', 4, 4, true], ['at_least', 4, 7, true],
            ['only', 3, 2, false], ['only', 3, 3, true], ['only', 3, 4, false],
            ['at_most', 2, 1, true], ['at_most', 2, 2, true], ['at_most', 2, 3, false], ['none', null, 1, true]];
    }

    #[DataProvider('conditions')]
    public function test_conditions_presentation_draw_and_immutable_snapshot(string $mode, ?int $required, int $level, bool $eligible): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser($level);
        $gacha = $this->rankGacha('standard', $mode, $required);
        $catalog = app(V2CatalogReadService::class);
        $presentation = $catalog->presentationState($gacha->public_id, $user);
        self::assertSame($eligible, $presentation['eligible']);
        self::assertSame($eligible ? null : 'member_rank_not_eligible', $presentation['ineligible_reason']);
        self::assertSame($mode, $catalog->getByPublicId($gacha->public_id)['member_rank_condition']['mode']);
        self::assertSame('authentication_required', $catalog->presentationState($gacha->public_id, null)['ineligible_reason']);
        $list = $catalog->list(100, null, null, null, $user);
        self::assertContains($gacha->public_id, array_column($list['data'], 'id'));
        if (! $eligible) {
            $this->assertDrawDenied($user, $gacha, 'GACHA_MEMBER_RANK_NOT_ELIGIBLE');
            self::assertSame(0, DB::table('draw_requests')->where('user_id', $user->id)->count());
            return;
        }
        $draw = $this->rankDraw($user, $gacha, 'm5-replay');
        self::assertSame($level, $draw['member_rank']['level']);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['level' => 1]);
        self::assertSame($draw['member_rank'], $this->rankDraw($user, $gacha, 'm5-replay')['member_rank']);
        self::assertSame($draw['member_rank'], app(V2DrawService::class)->history($user, null, 20)['items'][0]['member_rank']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_rank_conditions_and_audience_are_independent_and_rank_precedes_daily_limit(): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser(2);
        $gacha = $this->rankGacha('standard', 'at_most', 2, ['audience_code' => 'first_time_users']);
        self::assertSame('audience_not_eligible', app(V2CatalogReadService::class)->presentationState($gacha->public_id, $user)['ineligible_reason']);
        $this->assertDrawDenied($user, $gacha, 'GACHA_AUDIENCE_NOT_ELIGIBLE');
        $limited = $this->rankGacha('standard', 'only', 2, ['daily_draw_limit' => 1]);
        $this->rankDraw($user, $limited);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['level' => 3]);
        $this->assertDrawDenied($user, $limited, 'GACHA_MEMBER_RANK_NOT_ELIGIBLE');
        self::assertSame('member_rank_not_eligible', app(V2CatalogReadService::class)->presentationState($limited->public_id, $user)['ineligible_reason']);
    }

    public function test_login_global_daily_limit_after_promotion_next_day_and_signup_exclusion(): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser(2);
        $first = $this->rankGacha('login_daily', 'only', 2);
        $second = $this->rankGacha('login_daily', 'only', 3);
        $signup = $this->rankGacha('signup_once');
        $this->rankDraw($user, $signup);
        $this->rankDraw($user, $first);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['level' => 3, 'skip_count' => 1]);
        $this->assertDrawDenied($user, $second, 'DAILY_DRAW_LIMIT_EXCEEDED');
        $read = app(V2LoginCatalogReadService::class);
        self::assertSame('already_used', $read->detail($second->public_id, $user)['data']['eligibility']['reason']);
        $entry = collect($read->listing($user)['items'])->firstWhere('id', $second->public_id);
        self::assertSame('already_used', $entry['eligibility']['reason']);
        $this->rankClock('2026-10-31T15:00:00Z');
        self::assertSame(3, $this->rankDraw($user, $second)['member_rank']['level']);
        $this->assertDrawDenied($user, $signup, 'DAILY_DRAW_LIMIT_EXCEEDED');
    }

    public function test_off_ignores_rank_conditions_and_preserves_per_gacha_login_limit(): void
    {
        $user = $this->fundedRankUser(1);
        $first = $this->rankGacha('login_daily', 'only', 7);
        $second = $this->rankGacha('login_daily', 'only', 6);
        self::assertNull($this->rankDraw($user, $first)['member_rank']);
        self::assertNull($this->rankDraw($user, $second)['member_rank']);
        $this->assertDrawDenied($user, $first, 'DAILY_DRAW_LIMIT_EXCEEDED');
        self::assertSame(0, DB::table('member_rank_changes')->count());
        self::assertSame([], app(V2CatalogReadService::class)->list(10, null, null, null, $user, true)['data']);
    }

    public function test_month_boundary_uses_lazy_rank_and_filter_keeps_cursor_correct(): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser(4);
        $gold = $this->rankGacha('standard', 'at_least', 4);
        $silver = $this->rankGacha('standard', 'only', 3);
        $another = $this->rankGacha('standard', 'at_most', 3);
        $this->rankClock('2026-10-31T15:00:00Z');
        $catalog = app(V2CatalogReadService::class);
        $first = $catalog->list(1, null, null, null, $user, true);
        self::assertCount(1, $first['data']);
        self::assertTrue($first['meta']['has_more']);
        $next = $catalog->list(1, $first['meta']['next_cursor'], null, null, $user, true);
        self::assertEqualsCanonicalizing([$silver->public_id, $another->public_id], [$first['data'][0]['id'], $next['data'][0]['id']]);
        self::assertFalse($next['meta']['has_more']);
        $this->assertDrawDenied($user, $gold, 'GACHA_MEMBER_RANK_NOT_ELIGIBLE');
        self::assertSame(3, $this->rankDraw($user, $silver)['member_rank']['level']);
        self::assertSame(3, app(V2MemberRankResolver::class)->project($user->id)['level']);
    }

    public function test_copy_retains_condition_and_signup_cannot_receive_it(): void
    {
        $source = $this->rankGacha('login_daily', 'only', 4);
        $copy = app(V2GachaCopyService::class)->projection($source->public_id, true);
        self::assertSame('only', $copy['member_rank_mode']);
        self::assertSame(4, $copy['member_rank_level']);
        $this->expectException(V2CatalogException::class);
        app(V2GachaCompositionService::class)->validate($this->rankGachaInput('signup_once', 'only', 4));
    }

    public static function invalidConditions(): array
    {
        return [['none', 2], ['only', null], ['at_least', 0], ['at_most', 8], ['only', '2'], ['unknown', 2], [null, null]];
    }

    #[DataProvider('invalidConditions')]
    public function test_invalid_conditions_are_rejected(mixed $mode, mixed $level): void
    {
        $this->expectException(V2CatalogException::class);
        V2MemberRankGachaCondition::validate(['member_rank_mode' => $mode, 'member_rank_level' => $level]);
    }

    public function test_database_rejects_snapshot_rewrite(): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser(4);
        $draw = $this->rankDraw($user, $this->rankGacha('standard', 'only', 4));
        $this->expectException(QueryException::class);
        DB::table('draw_requests')->where('public_id', $draw['id'])->update(['member_rank_level' => 3]);
    }

    public function test_http_condition_and_403_use_the_existing_security_surface(): void
    {
        $this->enableRankFixture();
        $user = $this->fundedRankUser(2);
        $gacha = $this->rankGacha('standard', 'only', 4);
        $this->getJson('/api/v2/gachas/'.$gacha->public_id)->assertOk()
            ->assertJsonPath('data.member_rank_condition.rank.code', 'gold')
            ->assertJsonMissingPath('data.member_rank');
        Auth::guard('v2_user')->setUser($user);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $csrf = str_repeat('b', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://storefront.example.test', 'X-XSRF-TOKEN' => $csrf, 'Sec-Fetch-Site' => 'same-origin',
                'Idempotency-Key' => 'member-rank-http-denied'])
            ->postJson('/api/v2/gachas/'.$gacha->public_id.'/draws', ['draw_count' => 1])
            ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'GACHA_MEMBER_RANK_NOT_ELIGIBLE');
        self::assertSame(0, DB::table('draw_requests')->where('user_id', $user->id)->count());
    }

    public function test_database_conditions_and_published_immutability_fail_closed(): void
    {
        $gacha = $this->rankGacha('standard', 'only', 4);
        foreach ([['member_rank_mode' => 'none', 'member_rank_level' => null], ['member_rank_level' => 3]] as $changes) {
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('id', $gacha->published_version_id)->update($changes));
        }
        $service = app(V2GachaCompositionService::class);
        $draft = $service->save($service->validate($this->rankGachaInput()), null, null, $this->rankAdmin());
        foreach ([['member_rank_mode' => 'only'], ['member_rank_level' => 2],
            ['member_rank_mode' => 'only', 'member_rank_level' => 8], ['member_rank_mode' => 'unknown']] as $changes) {
            $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('gacha_id', $draft->id)->update($changes));
        }
        $signup = $service->save($service->validate($this->rankGachaInput('signup_once')), null, null, $this->rankAdmin());
        $this->rejectSql(fn () => DB::table('catalog_gacha_versions')->where('gacha_id', $signup->id)
            ->update(['member_rank_mode' => 'only', 'member_rank_level' => 4]));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function rejectSql(callable $write): void
    {
        try {
            DB::transaction($write);
            self::fail('Invalid database mutation was accepted.');
        } catch (QueryException $exception) {
            self::assertContains($exception->errorInfo[0], ['P0001', '23514']);
        }
    }

    private function assertDrawDenied(object $user, object $gacha, string $code): void
    {
        try {
            $this->rankDraw($user, $gacha);
            self::fail('Expected Draw rejection.');
        } catch (V2DrawException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
