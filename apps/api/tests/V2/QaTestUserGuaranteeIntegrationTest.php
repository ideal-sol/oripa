<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Domain\QaDraw\Services\V2QaPlanManagementService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QaTestUserGuaranteeIntegrationTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-13T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 2_000;
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10, 100, 1000];
        foreach ($fixture['gacha_prizes'] as &$relation) {
            $relation['initial_inventory'] = 2_000;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(static fn (): int => 500_000)
        );
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    /** @return array<string, array{int}> */
    public static function drawCounts(): array
    {
        return [
            'single' => [1],
            'five' => [5],
            'ten' => [10],
            'hundred' => [100],
            'thousand' => [1000],
        ];
    }

    #[DataProvider('drawCounts')]
    public function test_one_guaranteed_result_and_normal_remainder_are_canonical(int $count): void
    {
        [$owner, $user] = $this->actors();
        $context = $this->context($owner);
        $this->enableGuarantee($context, $user);
        $walletBefore = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $key = 'qa-guarantee-draw-'.$count;
        $response = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            $key,
            (string) Str::uuid7()
        );
        $request = DB::table('draw_requests')->where('public_id', $response['id'])->first();
        $results = DB::table('draw_results')
            ->where('draw_request_id', $request->id)
            ->orderBy('request_sequence')
            ->get();

        self::assertSame($count, $response['requested_count']);
        self::assertSame($count, $response['executed_count']);
        self::assertCount($count, $results);
        self::assertTrue((bool) $results->first()->is_qa_draw);
        self::assertNotNull($results->first()->qa_gacha_guarantee_assignment_id);
        self::assertSame(
            max(0, $count - 1),
            $results->slice(1)->where('is_qa_draw', false)->count()
        );
        self::assertSame(
            0,
            $results->slice(1)->whereNotNull('qa_gacha_guarantee_assignment_id')->count()
        );
        self::assertSame($count, DB::table('user_prizes')
            ->whereIn('draw_result_id', $results->pluck('id'))->count());
        self::assertSame($count, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($count, (int) DB::table('prize_inventories')->sum('won_count'));
        self::assertSame(
            $walletBefore - ($count * 100),
            (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance')
        );
        self::assertDatabaseHas('qa_draw_executions', [
            'draw_request_id' => $request->id,
            'qa_draw_plan_id' => null,
            'executed_count' => $count,
        ]);

        $replay = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            $key,
            (string) Str::uuid7()
        );
        self::assertSame($response['id'], $replay['id']);
        self::assertSame($count, DB::table('draw_results')
            ->where('draw_request_id', $request->id)->count());
        self::assertSame($count, (int) DB::table('gacha_draw_states')->value('sold_count'));
    }

    public function test_mode_is_indefinite_and_no_assignment_or_disabled_mode_draws_normally(): void
    {
        [$owner, $user] = $this->actors();
        $context = $this->context($owner);
        $mode = app(V2QaDrawAdminService::class)->saveMode(
            $context,
            $user->public_id,
            'Indefinite QA mode'
        );
        self::assertNull($mode['ends_at']);
        CarbonImmutable::setTestNow('2027-08-13T00:00:00Z');
        self::assertTrue(app(V2QaDrawAdminService::class)
            ->mode($this->context($owner), $user->public_id)['mode']['is_active']);
        CarbonImmutable::setTestNow('2026-08-13T00:00:00Z');
        $normal = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'qa-no-assignment-normal',
            (string) Str::uuid7()
        );
        self::assertDatabaseHas('draw_requests', ['public_id' => $normal['id'], 'is_qa_draw' => false]);
        $this->enableGuarantee($this->context($owner), $user);
        $assignment = DB::table('qa_gacha_guarantee_assignments')->first();
        app(V2QaPlanManagementService::class)->disableGachaGuarantee(
            $this->context($owner),
            self::GACHA_ID,
            $user->public_id,
            'qa-disable-guarantee',
            ['revision' => $assignment->revision]
        );
        $afterDisable = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'qa-disabled-assignment-normal',
            (string) Str::uuid7()
        );
        self::assertDatabaseHas('draw_requests', [
            'public_id' => $afterDisable['id'],
            'is_qa_draw' => false,
        ]);
    }

    public function test_inventory_failure_and_cross_gacha_assignment_fail_closed(): void
    {
        [$owner, $user] = $this->actors();
        $this->enableGuarantee($this->context($owner), $user);
        $assignment = DB::table('qa_gacha_guarantee_assignments')->first();
        $relationId = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', self::PRIZE_ID)
            ->value('relation.id');
        DB::table('prize_inventories')->where('gacha_version_prize_id', $relationId)
            ->update(['won_count' => DB::raw('initial_quantity')]);
        $wallet = (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance');
        try {
            app(V2DrawService::class)->create(
                $user,
                self::GACHA_ID,
                5,
                'qa-insufficient-guarantee',
                (string) Str::uuid7()
            );
            self::fail('An unavailable guaranteed Prize must fail closed.');
        } catch (V2DrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
        }
        self::assertDatabaseCount('draw_requests', 0);
        self::assertSame($wallet, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));

        $gacha = (array) DB::table('catalog_gachas')->first();
        unset($gacha['id']);
        $gacha['public_id'] = (string) Str::uuid7();
        $gacha['public_code'] = Str::random(11);
        $gacha['code'] = 'qa-cross-'.Str::lower(Str::random(10));
        $gacha['slug'] = $gacha['code'];
        $gacha['published_version_id'] = null;
        $gacha['active_draw_state_id'] = null;
        $gacha['management_status'] = 'draft';
        $gacha['state'] = 'draft';
        $gacha['sold_count'] = 0;
        $gacha['revision'] = 1;
        $otherGachaId = DB::table('catalog_gachas')->insertGetId($gacha);
        $prize = (array) DB::table('catalog_prizes')->first();
        unset($prize['id']);
        $prize['public_id'] = (string) Str::uuid7();
        $prize['code'] = 'qa-cross-prize-'.Str::lower(Str::random(8));
        $prize['gacha_id'] = $otherGachaId;
        $prize['revision'] = 1;
        $otherPrizeId = DB::table('catalog_prizes')->insertGetId($prize);
        try {
            DB::table('qa_gacha_guarantee_assignments')->where('id', $assignment->id)->update([
                'prize_id' => $otherPrizeId,
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);
            self::fail('Cross-Gacha guaranteed Prize assignment must be rejected.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('Cross-Gacha QA Prize assignment', $exception->getMessage());
        }
    }

    public function test_partial_remaining_draw_keeps_one_guarantee_and_canonical_executed_count(): void
    {
        [$owner, $user] = $this->actors();
        $this->enableGuarantee($this->context($owner), $user);
        DB::table('gacha_draw_states')->update([
            'sold_count' => 1_100,
            'lock_version' => DB::raw('lock_version + 1'),
        ]);
        DB::table('prize_inventories')->update([
            'initial_quantity' => 2_000,
            'won_count' => 550,
            'lock_version' => DB::raw('lock_version + 1'),
        ]);

        $response = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1000,
            'qa-guarantee-partial-remaining',
            (string) Str::uuid7()
        );
        $request = DB::table('draw_requests')->where('public_id', $response['id'])->first();

        self::assertSame(1000, $response['requested_count']);
        self::assertSame(900, $response['executed_count']);
        self::assertSame(900, DB::table('draw_results')
            ->where('draw_request_id', $request->id)->count());
        self::assertSame(1, DB::table('draw_results')
            ->where('draw_request_id', $request->id)
            ->where('is_qa_draw', true)->count());
        self::assertDatabaseHas('qa_draw_executions', [
            'draw_request_id' => $request->id,
            'executed_count' => 900,
        ]);
        self::assertDatabaseHas('gacha_draw_states', [
            'sold_count' => 2_000,
            'status' => 'sold_out',
        ]);

        $replay = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1000,
            'qa-guarantee-partial-remaining',
            (string) Str::uuid7()
        );
        self::assertSame($response['id'], $replay['id']);
        self::assertSame(900, $replay['executed_count']);
        self::assertSame(1, DB::table('draw_results')
            ->where('draw_request_id', $request->id)
            ->where('is_qa_draw', true)->count());
    }

    /** @return array{Admin, User} */
    private function actors(): array
    {
        $owner = Admin::query()->create([
            'email_display' => 'qa-owner-'.Str::uuid().'@example.test',
            'email_normalized' => 'qa-owner-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        $user = User::query()->create([
            'email_display' => 'qa-user-'.Str::uuid().'@example.test',
            'email_normalized' => 'qa-user-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            200_000,
            now()->addYears(2),
            'qa-guarantee-points-'.Str::uuid()
        );

        return [$owner, $user];
    }

    private function context(Admin $admin): V2AdminAuthorizationContext
    {
        $hash = hash('sha256', random_bytes(32));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(12),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }

    private function enableGuarantee(
        V2AdminAuthorizationContext $context,
        User $user,
        ?int $revision = null
    ): void {
        app(V2QaDrawAdminService::class)->saveMode(
            $context,
            $user->public_id,
            'Persistent QA guarantee'
        );
        app(V2QaPlanManagementService::class)->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-guarantee-save-'.Str::uuid(),
            array_filter([
                'revision' => $revision,
                'user_id' => $user->public_id,
                'prize_id' => self::PRIZE_ID,
            ], static fn (mixed $value): bool => $value !== null)
        );
    }
}
