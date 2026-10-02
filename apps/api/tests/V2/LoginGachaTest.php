<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2FixedPercentage;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\Catalog\Services\V2LoginCatalogReadService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class LoginGachaTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('local');
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR));
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'email_display' => 'login-admin@example.test', 'email_normalized' => 'login-admin@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('test login admin password'),
            'role' => 'owner', 'state' => 'active',
        ]);
        $this->adminToken = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($this->adminToken), 'admin_id' => $adminId,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false, 'created_at' => $created, 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => $created->copy()->addHours(8),
        ]));
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(
            static function (int $minimum, int $maximum): int {
                self::assertSame(1, $minimum);
                self::assertSame(V2FixedPercentage::SCALE, $maximum);

                return 1;
            }
        ));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_free_login_bulk_publish_draw_replay_and_jst_daily_boundary(): void
    {
        $gacha = $this->createPublished();
        $user = $this->user();
        CarbonImmutable::setTestNow('2026-10-01T14:59:59Z');
        $response = $this->draw($user, $gacha, 'daily-first');
        self::assertSame(0, $response['point_cost_total']);
        self::assertSame(0, $response['wallet_after']['total_points']);
        self::assertSame(0, DB::table('wallets')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        self::assertTrue($this->draw($user, $gacha, 'daily-first')['idempotent_replay']);
        $this->rejectDraw($user, $gacha, 'daily-second', 'DAILY_DRAW_LIMIT_EXCEEDED');
        CarbonImmutable::setTestNow('2026-10-01T15:00:00Z');
        self::assertFalse($this->draw($user, $gacha, 'daily-next')['idempotent_replay']);
        self::assertSame(2, DB::table('draw_requests')->where('user_id', $user->id)->count());
        self::assertNull(DB::table('gacha_draw_states')->where('gacha_id', DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('id'))->value('total_count'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_signup_requires_qualification_at_start_and_is_once_without_expiry(): void
    {
        $gacha = $this->createPublished('signup_once');
        CarbonImmutable::setTestNow('2026-10-01T00:00:00Z');
        $before = $this->user('2026-06-30T23:59:59Z');
        $this->rejectDraw($before, $gacha, 'signup-before', 'GACHA_AUDIENCE_NOT_ELIGIBLE');
        $unknown = $this->user(null);
        self::assertTrue($unknown->created_at->greaterThan(CarbonImmutable::parse('2026-07-01T00:00:00Z')));
        self::assertTrue($unknown->email_verified_at->greaterThan(CarbonImmutable::parse('2026-07-01T00:00:00Z')));
        $this->rejectDraw($unknown, $gacha, 'signup-unknown', 'GACHA_AUDIENCE_NOT_ELIGIBLE');
        $atStart = $this->user('2026-07-01T00:00:00Z');
        CarbonImmutable::setTestNow('2027-10-01T00:00:00Z');
        $this->rejectDraw($unknown, $gacha, 'signup-unknown-later', 'GACHA_AUDIENCE_NOT_ELIGIBLE');
        self::assertNull($unknown->fresh()->first_registration_qualified_at);
        $this->draw($atStart, $gacha, 'signup-first');
        CarbonImmutable::setTestNow('2028-10-01T00:00:00Z');
        $this->rejectDraw($atStart, $gacha, 'signup-second', 'DAILY_DRAW_LIMIT_EXCEEDED');
        self::assertTrue($this->draw($atStart, $gacha, 'signup-first')['idempotent_replay']);
    }

    public function test_login_admin_core_version_publish_and_sales_readbacks_have_no_numeric_capacity(): void
    {
        foreach (['login_daily', 'signup_once'] as $type) {
            $gacha = $this->createPublished($type);
            $root = '/admin/api/v2/catalog/gachas/'.$gacha['id'];
            Auth::forgetGuards();
            $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->adminToken)
                ->getJson($root)->assertOk()->assertJsonPath('data.gacha_type', $type)
                ->assertJsonPath('data.current_version.total_count', null);
            $this->getJson($root.'/versions/'.$gacha['current_version']['id'])->assertOk()
                ->assertJsonPath('data.total_count', null);
            $this->getJson($root.'/publish-state')->assertOk()->assertJsonPath('data.draw_state.total_count', null);
            $this->getJson($root.'/sales-state')->assertOk()->assertJsonPath('data.draw_state.total_count', null);
        }
    }

    public function test_copy_is_read_only_clears_period_and_saves_independent_initial_inventory(): void
    {
        $source = $this->createPublished();
        $this->draw($this->user(), $source, 'copy-source-draw');
        $before = DB::table('catalog_gachas')->count();
        $projection = app(V2GachaCopyService::class)->projection($source['id'], true);
        self::assertSame($before, DB::table('catalog_gachas')->count());
        self::assertNull($projection['publish_start_at']);
        self::assertNull($projection['publish_end_at']);
        self::assertSame(3, $projection['prizes'][0]['initial_inventory']);
        self::assertSame('0.0000000001', $projection['prizes'][0]['percentage']);
        $projection['publish_start_at'] = '2026-07-01T00:00:00Z';
        $copy = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $projection)->assertCreated()->json('data');
        self::assertNotSame($source['id'], $copy['id']);
        self::assertNotSame($source['public_code'], $copy['public_code']);
        self::assertSame('draft', $copy['publication_status']);
        self::assertSame(1, $copy['current_version']['version_number']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_bulk_validation_rejects_hidden_fields_shipping_minimum_and_inexact_total(): void
    {
        $input = $this->input();
        foreach ([
            ['total_count', 10], ['category_id', '0198a001-0000-7000-8000-000000000001'],
            ['allowed_draw_counts', [1, 5]], ['gacha_type', 'invalid'],
        ] as [$field, $value]) {
            $invalid = $input;
            $invalid[$field] = $value;
            $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $invalid)->assertStatus(422);
        }
        foreach ([['shipping_only', true], ['exchange_points', 0], ['percentage', '99.9999999998'], ['percentage', '0.00000000001']] as [$field, $value]) {
            $invalid = $input;
            $invalid['prizes'][1][$field] = $value;
            $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $invalid)->assertStatus(422);
        }
        self::assertSame(1, DB::table('catalog_gachas')->count());
    }

    public function test_bulk_update_is_atomic_and_requires_the_current_revisions(): void
    {
        $input = $this->input();
        $gacha = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $input)->assertCreated()->json('data');
        $input['prizes'][0]['percentage'] = '40';
        $input['prizes'][1]['percentage'] = '60';
        $input['title'] = 'Updated together';
        $payload = ['expected_revision' => $gacha['revision'], 'expected_version_revision' => $gacha['current_version']['revision'], 'composition' => $input];
        $updated = $this->mutate('PUT', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/composition', $payload)->assertOk()->json('data');
        self::assertSame(2, $updated['revision']);
        self::assertSame('40', app(V2GachaCopyService::class)->projection($gacha['id'], false)['prizes'][0]['percentage']);
        $this->mutate('PUT', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/composition', $payload)->assertStatus(409);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_inventory_zero_hides_guest_top_blocks_all_draws_and_refill_restores_without_sales_pause(): void
    {
        $gacha = $this->createPublished();
        $user = $this->user();
        $prize = DB::table('catalog_gacha_version_prizes as relation')->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('prize_inventories as inventory', 'inventory.gacha_version_prize_id', '=', 'relation.id')
            ->where('relation.gacha_version_id', DB::table('catalog_gacha_versions')->where('public_id', $gacha['current_version']['id'])->value('id'))
            ->orderBy('relation.id')->first(['prize.public_id', 'inventory.lock_version', 'inventory.id']);
        $path = '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/login-inventory/'.$prize->public_id;
        self::assertCount(1, app(V2LoginCatalogReadService::class)->listing()['items']);
        $this->mutate('PUT', $path, ['expected_revision' => (int) $prize->lock_version, 'available_quantity' => 0, 'reason' => 'Synthetic stock withdrawal'])->assertOk();
        self::assertSame([], app(V2LoginCatalogReadService::class)->listing()['items']);
        $this->rejectDraw($user, $gacha, 'empty-stock', 'PRIZE_INVENTORY_UNAVAILABLE');
        self::assertSame('published', DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('management_status'));
        $revision = (int) DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('revision');
        $this->mutate('POST', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/sales-pause',
            ['expected_gacha_revision' => $revision, 'reason_code' => 'inventory_review'])->assertOk()
            ->assertJsonPath('data.draw_state.total_count', null);
        $this->mutate('PUT', $path, ['expected_revision' => (int) $prize->lock_version + 1, 'available_quantity' => 3, 'reason' => 'Blocked while paused'])->assertStatus(409);
        $this->mutate('POST', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/sales-resume',
            ['expected_gacha_revision' => $revision + 1])->assertOk()->assertJsonPath('data.draw_state.total_count', null);
        self::assertSame([], app(V2LoginCatalogReadService::class)->listing()['items']);
        $this->rejectDraw($user, $gacha, 'resumed-still-empty', 'PRIZE_INVENTORY_UNAVAILABLE');
        $this->mutate('PUT', $path, ['expected_revision' => (int) $prize->lock_version + 1, 'available_quantity' => 3, 'reason' => 'Synthetic replenishment'])->assertOk();
        self::assertCount(1, app(V2LoginCatalogReadService::class)->listing()['items']);
        self::assertSame('0.0000000001', app(V2GachaCopyService::class)->projection($gacha['id'], true)['prizes'][0]['percentage']);
        $this->draw($user, $gacha, 'refilled');
        self::assertSame(2, DB::table('prize_inventory_adjustments')->count());
        self::assertSame(1, (int) DB::table('prize_inventories')->where('id', $prize->id)->value('awarded_count'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_public_login_routes_require_auth_for_detail_and_cannot_bypass_old_routes(): void
    {
        $gacha = $this->createPublished();
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withUnencryptedCookie('__Host-oripa_admin_session', '');
        $this->getJson('/api/v2/login-gachas')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonMissingPath('items.0.eligibility')->assertJsonMissingPath('items.0.total_count');
        $this->getJson('/api/v2/login-gachas/'.$gacha['id'])->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')->assertHeader('Vary', 'Cookie')
            ->assertJsonStructure(['type', 'title', 'status', 'code', 'request_id', 'retryable'])
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')->assertJsonPath('retryable', false);
        foreach ([$gacha['id'], $gacha['public_code']] as $identifier) {
            $this->getJson('/api/v2/gachas/'.$identifier)->assertNotFound();
        }
        $this->getJson('/api/v2/gachas/by-slug/'.$gacha['slug'])->assertNotFound();
        Auth::guard('v2_user')->setUser($this->user());
        $response = $this->getJson('/api/v2/login-gachas/'.$gacha['id'])->assertOk()
            ->assertHeader('Vary', 'Cookie')->assertJsonPath('data.eligibility.eligible', true)
            ->assertJsonMissingPath('data.prizes.0.percentage')->assertJsonMissingPath('data.prizes.0.rate_units');
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_login_detail_requires_a_live_user_session_and_preserves_the_problem_contract(): void
    {
        $gacha = $this->createPublished();
        $user = $this->user();
        $manager = app(\App\Domain\Identity\Services\V2SessionManager::class);
        $realm = \App\Domain\Identity\Enums\V2Realm::User;
        $valid = $manager->issue($realm, $user->id);
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withUnencryptedCookie('__Host-oripa_admin_session', '')
            ->withUnencryptedCookie('__Host-oripa_user_session', $valid['token'])
            ->getJson('/api/v2/login-gachas/'.$gacha['id'])->assertOk()
            ->assertJsonPath('data.eligibility.eligible', true);
        foreach (['missing', 'invalid', 'revoked', 'expired'] as $state) {
            $session = $manager->issue($realm, $user->id);
            $token = $session['token'];
            if ($state === 'missing') {
                $token = '';
            } elseif ($state === 'invalid') {
                $token = str_repeat('f', 64);
            } elseif ($state === 'revoked') {
                DB::table('user_sessions')->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($token))
                    ->update(['revoked_at' => now()]);
            } else {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addYear());
            }
            Auth::forgetGuards();
            $response = $this->withUnencryptedCookie('__Host-oripa_user_session', $token)
                ->getJson('/api/v2/login-gachas/'.$gacha['id'])->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/problem+json')->assertHeader('Vary', 'Cookie')
                ->assertJsonStructure(['type', 'title', 'status', 'code', 'request_id', 'retryable'])
                ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')->assertJsonPath('status', 401)
                ->assertJsonMissingPath('data');
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            CarbonImmutable::setTestNow();
        }
    }

    public function test_fixed_ticket_endpoints_and_replay_preserve_probability_checksum_and_rank_snapshot(): void
    {
        $gacha = $this->createPublished();
        $first = $this->draw($this->user(), $gacha, 'first-ticket');
        $master = DB::table('catalog_rank_masters')->orderBy('id')->first();
        $revision = DB::table('catalog_rank_master_revisions')->where('id', $master->current_revision_id)->first();
        $attributes = (array) $revision;
        unset($attributes['id']);
        $attributes['revision_number']++;
        $attributes['rank_name'] = 'Changed shared Rank';
        $newRevision = DB::table('catalog_rank_master_revisions')->insertGetId($attributes);
        DB::table('catalog_rank_masters')->where('id', $master->id)->update(['current_revision_id' => $newRevision, 'revision' => $master->revision + 1]);
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static fn (int $minimum, int $maximum): int => $maximum));
        $last = $this->draw($this->user(), $gacha, 'last-ticket');
        self::assertNotSame($first['results'][0]['prize']['id'], $last['results'][0]['prize']['id']);
        self::assertSame($first['results'][0]['rank_name_snapshot'], $last['results'][0]['rank_name_snapshot']);
        self::assertSame([0, V2FixedPercentage::SCALE - 1], DB::table('draw_results')->orderBy('id')->pluck('random_value')->map(fn ($value): int => (int) $value)->all());
        self::assertSame(1, DB::table('draw_requests')->distinct()->count('catalog_snapshot_sha256'));
        self::assertSame((int) $revision->revision_number, app(V2GachaCopyService::class)->projection($gacha['id'], true)['ranks'][0]['rank_revision_number']);
        self::assertSame($revision->rank_name, app(V2GachaCopyService::class)->projection($gacha['id'], true)['ranks'][0]['presentation']['name']);
        self::assertSame($revision->rank_name, app(V2LoginCatalogReadService::class)->detail($gacha['id'], $this->user())['data']['ranks'][0]['name']);
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->adminToken)
            ->getJson('/admin/api/v2/catalog/gachas/'.$gacha['id'].'/ranks')->assertOk()
            ->assertJsonPath('items.0.rank.rank_name', $revision->rank_name);
    }

    public function test_paid_login_uses_point_accounting_and_failure_rolls_back_quota_and_economics(): void
    {
        $input = $this->input();
        $input['price_points'] = 10;
        $gacha = $this->createPublished(input: $input);
        $user = $this->user();
        app(V2PointService::class)->grantFree($user->id, 100, 'login-paid-grant');
        $operationsBefore = DB::table('point_operations')->count();
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static function (): int {
            throw new \RuntimeException('Synthetic failure after consumption');
        }));
        try {
            $this->draw($user, $gacha, 'rolled-back');
            self::fail('Synthetic failure must escape.');
        } catch (V2DrawException $exception) {
            self::assertSame('DRAW_INTERNAL_ERROR', $exception->errorCode);
        }
        self::assertSame(0, DB::table('draw_requests')->where('user_id', $user->id)->count());
        self::assertSame($operationsBefore, DB::table('point_operations')->count());
        self::assertSame(100, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static fn (): int => 1));
        $success = $this->draw($user, $gacha, 'rolled-back');
        self::assertSame(10, $success['point_cost_total']);
        self::assertSame(90, $success['wallet_after']['free_points']);
    }

    public function test_free_wallet_after_excludes_reservations_and_expired_lots_without_mutation_and_replays_saved_balance(): void
    {
        $gacha = $this->createPublished();
        $user = $this->user();
        $instant = CarbonImmutable::now()->startOfSecond();
        DB::table('wallets')->insert([
            'user_id' => $user->id, 'paid_balance' => 60, 'paid_reserved_balance' => 20,
            'free_balance' => 200, 'free_reserved_balance' => 5, 'lock_version' => 0, 'created_at' => $instant, 'updated_at' => $instant,
        ]);
        foreach ([[$instant->subSecond(), 0], [$instant->addDay(), 5]] as [$expires, $reserved]) {
            $operation = DB::table('point_operations')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'user_id' => $user->id, 'operation_type' => 'free_grant',
                'business_key' => 'login-wallet-fixture:'.Str::uuid7(), 'source_type' => 'admin_adjustment', 'actor_type' => 'system',
                'is_qa' => false, 'occurred_at' => $instant->subDays(2), 'business_date' => $instant->subDays(2)->setTimezone('Asia/Tokyo')->toDateString(),
                'metadata' => '{}', 'created_at' => $instant->subDays(2),
            ]);
            DB::table('point_lots')->insert([
                'user_id' => $user->id, 'grant_operation_id' => $operation, 'point_type' => 'free',
                'granted_amount' => 100, 'remaining_amount' => 100, 'reserved_amount' => $reserved,
                'granted_at' => $instant->subDays(2), 'expire_at' => $expires, 'legacy_no_expiry' => false,
            ]);
        }
        CarbonImmutable::setTestNow($instant);
        $before = DB::table('point_lots')->where('user_id', $user->id)->orderBy('id')->get()->all();
        $response = $this->draw($user, $gacha, 'free-available-wallet');
        self::assertEquals(['free_points' => 95, 'paid_points' => 40, 'total_points' => 135], $response['wallet_after']);
        self::assertEquals($before, DB::table('point_lots')->where('user_id', $user->id)->orderBy('id')->get()->all());
        self::assertSame(2, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_ledger_entries')->where('user_id', $user->id)->count());
        CarbonImmutable::setTestNow($instant->addDays(2));
        self::assertEquals($response['wallet_after'], $this->draw($user, $gacha, 'free-available-wallet')['wallet_after']);
    }

    public function test_published_and_sales_paused_login_content_and_zero_standard_price_are_guarded(): void
    {
        $gacha = $this->createPublished();
        $canonical = DB::table('catalog_gachas')->where('public_id', $gacha['id'])->first();
        $this->databaseRejects(fn () => DB::table('catalog_gachas')->where('id', $canonical->id)->update(['gacha_type' => 'standard', 'category_id' => DB::table('catalog_categories')->value('id'), 'revision' => $canonical->revision + 1]));
        $this->databaseRejects(fn () => DB::table('catalog_gachas')->where('id', $canonical->id)->update(['current_title' => 'Changed', 'revision' => $canonical->revision + 1]));
        $rank = DB::table('catalog_gacha_ranks')->where('gacha_id', $canonical->id)->first();
        $this->databaseRejects(fn () => DB::table('catalog_gacha_ranks')->where('id', $rank->id)->update(['current_video_revision_id' => null, 'revision' => $rank->revision + 1]));
        $this->mutate('POST', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/sales-pause', ['expected_gacha_revision' => (int) $canonical->revision, 'reason_code' => 'operations_review'])->assertOk();
        $prize = DB::table('catalog_prizes')->where('gacha_id', $canonical->id)->first();
        $this->mutate('PUT', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/login-inventory/'.$prize->public_id,
            ['expected_revision' => 1, 'available_quantity' => 2, 'reason' => 'Must reject during pause'])->assertStatus(409);
        self::assertCount(2, app(V2GachaCopyService::class)->projection($gacha['id'], true)['prizes']);
        $user = $this->user();
        $this->databaseRejects(fn () => DB::table('users')->where('id', $user->id)->update(['first_registration_qualified_at' => null]));
        $standard = $this->input();
        $standard['gacha_type'] = 'standard';
        $standard['category_id'] = '0198a001-0000-7000-8000-000000000001';
        $standard['total_count'] = 6;
        $standard['minimum_exchange_points'] = null;
        $standard['prizes'] = array_map(fn (array $prize): array => [...$prize, 'percentage' => null], $standard['prizes']);
        $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $standard)->assertStatus(422);
        $standard['price_points'] = 1;
        $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $standard)->assertCreated();
    }

    public function test_daily_and_signup_are_independent_for_each_canonical_and_replay_does_not_consume_a_new_day(): void
    {
        $user = $this->user();
        foreach (['login_daily', 'signup_once'] as $type) {
            CarbonImmutable::setTestNow();
            $first = $this->createPublished($type);
            $second = $this->createPublished($type);
            $response = $this->draw($user, $first, $type.'-first');
            $this->draw($user, $second, $type.'-second');
            $this->rejectDraw($user, $first, $type.'-repeat', 'DAILY_DRAW_LIMIT_EXCEEDED');
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
            self::assertSame($response['id'], $this->draw($user, $first, $type.'-first')['id']);
            if ($type === 'login_daily') {
                $this->draw($user, $first, 'new-day-after-replay');
            } else {
                $this->rejectDraw($user, $first, 'signup-next-day', 'DAILY_DRAW_LIMIT_EXCEEDED');
            }
        }
    }

    public function test_paid_login_insufficient_points_does_not_record_usage_or_award(): void
    {
        $input = $this->input();
        $input['price_points'] = 10;
        $gacha = $this->createPublished(input: $input);
        $user = $this->user();
        $this->rejectDraw($user, $gacha, 'insufficient', 'INSUFFICIENT_POINTS');
        self::assertSame(0, DB::table('draw_requests')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('user_prizes')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
    }

    #[DataProvider('copyCases')]
    public function test_copy_supports_every_type_and_allowed_source_state(string $type, string $state): void
    {
        $input = $this->input($type);
        if ($type === 'standard') {
            $input = [...$input, 'price_points' => 1, 'category_id' => '0198a001-0000-7000-8000-000000000001', 'total_count' => 6];
            $input['prizes'] = array_map(fn (array $prize): array => [...$prize, 'percentage' => null], $input['prizes']);
        }
        $source = $state === 'draft'
            ? $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $input)->assertCreated()->json('data')
            : $this->createPublished($type, $input);
        if ($state === 'sales_paused') {
            $revision = (int) DB::table('catalog_gachas')->where('public_id', $source['id'])->value('revision');
            $this->mutate('POST', '/admin/api/v2/catalog/gachas/'.$source['id'].'/sales-pause',
                ['expected_gacha_revision' => $revision, 'reason_code' => 'operations_review'])->assertOk();
        }
        $counts = DB::table('catalog_gachas')->count();
        $projection = app(V2GachaCopyService::class)->projection($source['id'], true);
        self::assertSame($counts, DB::table('catalog_gachas')->count());
        self::assertNull($projection['publish_start_at']);
        self::assertNull($projection['publish_end_at']);
        self::assertSame($type, $projection['gacha_type']);
        self::assertEquals($input['prizes'], $projection['prizes']);
        $projection['publish_start_at'] = '2026-07-01T00:00:00Z';
        $copy = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $projection)->assertCreated()->json('data');
        self::assertNotSame($source['id'], $copy['id']);
        self::assertSame('draft', $copy['publication_status']);
        self::assertSame(1, $copy['current_version']['version_number']);
        self::assertSame(1, $copy['current_version']['revision']);
        self::assertSame($type, $copy['gacha_type']);
        $sourceOwner = (int) DB::table('catalog_gachas')->where('public_id', $source['id'])->value('id');
        $copyOwner = (int) DB::table('catalog_gachas')->where('public_id', $copy['id'])->value('id');
        self::assertSame([], array_intersect(DB::table('catalog_prizes')->where('gacha_id', $sourceOwner)->pluck('id')->all(),
            DB::table('catalog_prizes')->where('gacha_id', $copyOwner)->pluck('id')->all()));
        self::assertSame(0, DB::table('gacha_draw_states')->where('gacha_id', $copyOwner)->count());
        self::assertSame(6, (int) DB::table('prize_inventories')->whereIn('gacha_version_prize_id',
            DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', DB::table('catalog_gacha_versions')->where('gacha_id', $copyOwner)->value('id'))->select('id'))->sum('available_quantity'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function copyCases(): array
    {
        $cases = [];
        foreach (['standard', 'login_daily', 'signup_once'] as $type) {
            foreach (['draft', 'published', 'sales_paused'] as $state) {
                $cases[$type.'-'.$state] = [$type, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('failureCases')]
    public function test_login_draw_rolls_back_every_economic_boundary(string $table, string $operation): void
    {
        $input = $this->input();
        $input['price_points'] = 10;
        $gacha = $this->createPublished(input: $input);
        $user = $this->user();
        app(V2PointService::class)->grantFree($user->id, 100, 'login-atomic-grant');
        $lots = DB::table('point_lots')->where('user_id', $user->id)->get()->toJson();
        $operations = DB::table('point_operations')->count();
        $ledger = DB::table('point_ledger_entries')->count();
        $outbox = DB::table('outbox_messages')->count();
        DB::unprepared("CREATE FUNCTION v2_test_login_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic login failure'; END; $$;
            CREATE TRIGGER login_test_failure BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_test_login_failure();");
        $this->rejectDraw($user, $gacha, 'atomic-retry', 'DRAW_INTERNAL_ERROR');
        self::assertSame(100, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($lots, DB::table('point_lots')->where('user_id', $user->id)->get()->toJson());
        self::assertSame($operations, DB::table('point_operations')->count());
        self::assertSame($ledger, DB::table('point_ledger_entries')->count());
        self::assertSame($outbox, DB::table('outbox_messages')->count());
        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(0, DB::table('user_prizes')->count());
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('awarded_count'));
        DB::unprepared("DROP TRIGGER login_test_failure ON {$table}; DROP FUNCTION v2_test_login_failure();");
        self::assertSame(10, $this->draw($user, $gacha, 'atomic-retry')['point_cost_total']);
    }

    public static function failureCases(): array
    {
        return [['wallets', 'UPDATE'], ['prize_inventories', 'UPDATE'], ['draw_results', 'INSERT'], ['user_prizes', 'INSERT']];
    }

    public function test_scheduled_login_activates_the_same_fixed_snapshot_with_no_total_count(): void
    {
        $input = $this->input();
        $input['publish_start_at'] = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant)->addSeconds(3)->startOfSecond()->toIso8601String();
        $gacha = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $input)->assertCreated()->json('data');
        $root = '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$gacha['current_version']['id'];
        $schedule = $this->mutate('POST', $root.'/publish-schedule', ['scheduled_for' => $input['publish_start_at'],
            'expected_revision' => $gacha['current_version']['revision'], 'expected_gacha_revision' => $gacha['revision']])->assertCreated()->json('data');
        self::assertSame([], app(V2LoginCatalogReadService::class)->listing()['items']);
        self::assertSame($input['publish_start_at'], app(V2GachaCopyService::class)->projection($gacha['id'], false)['publish_start_at']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        sleep(4);
        self::assertSame(1, app(\App\Domain\Catalog\Services\V2ScheduledGachaPublishWorker::class)->run('login-local-test'));
        $scheduleAfter = DB::table('catalog_gacha_publish_schedules')->where('public_id', $schedule['id'])->first();
        self::assertSame('completed', $scheduleAfter->status, $scheduleAfter->failure_code ?? '');
        self::assertSame('published', DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('management_status'));
        $state = DB::table('gacha_draw_states')->where('gacha_id', DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('id'))->first();
        self::assertNull($state->total_count);
        self::assertSame($schedule['selected_probability']['snapshot_sha256'], DB::table('catalog_probability_versions')->where('id', $state->probability_version_id)->value('snapshot_sha256'));
        self::assertCount(1, app(V2LoginCatalogReadService::class)->listing()['items']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_qa_mode_does_not_bypass_login_quota_or_make_login_draws_qa(): void
    {
        $gacha = $this->createPublished();
        $user = $this->user();
        $this->mutate('PUT', '/admin/api/v2/users/'.$user->public_id.'/qa-mode', ['reason' => 'Synthetic login quota regression'])->assertOk();
        $result = $this->draw($user, $gacha, 'qa-mode-login');
        self::assertFalse((bool) DB::table('draw_requests')->where('public_id', $result['id'])->value('is_qa_draw'));
        $this->rejectDraw($user, $gacha, 'qa-mode-second', 'DAILY_DRAW_LIMIT_EXCEEDED');
    }

    private function databaseRejects(callable $mutation): void
    {
        try {
            DB::transaction(function () use ($mutation): void {
                $mutation();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            self::fail('Database guard must reject the mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_default_initializes_every_type_without_retroactivity_or_copy_replacement(): void
    {
        $default = '0198a001-0000-7000-8000-000000000006';
        $alternates = [];
        foreach (['B', 'C'] as $title) {
            $alternates[] = $this->mutate('POST', '/admin/api/v2/catalog/rank-effects?media_type=video', [
                'title' => $title, 'asset_type' => 'video', 'is_active' => true,
                'file_name' => 'video.mp4', 'mime_type' => 'video/mp4',
                'content_base64' => base64_encode(hex2bin('00000018667479706d703432000000006d70343269736f6d')),
            ])->assertCreated()->json('data.id');
        }
        $this->createPublished('login_daily');
        $this->createPublished('signup_once');
        $pinned = DB::table('catalog_gacha_version_ranks')->orderBy('id')->get()->toJson();
        $before = DB::table('catalog_gacha_ranks')->orderBy('id')->get()->toJson();
        DB::table('catalog_presentation_assets')->where('public_id', $default)->update(['is_default_rank_video' => true, 'revision' => DB::raw('revision + 1')]);
        foreach (['standard', 'login_daily', 'signup_once'] as $type) {
            $input = $this->input($type);
            $input['ranks'][0]['video_asset_id'] = null;
            if ($type === 'standard') {
                $input['category_id'] = '0198a001-0000-7000-8000-000000000001';
                $input['total_count'] = 6;
                $input['price_points'] = 1;
                foreach ($input['prizes'] as &$prize) {
                    $prize['percentage'] = null;
                }
                unset($prize);
            }
            $gacha = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $input)->assertCreated()->json('data');
            $projection = app(V2GachaCopyService::class)->projection($gacha['id'], false);
            self::assertSame($default, $projection['ranks'][0]['video_asset_id']);
            $gachaId = DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('id');
            self::assertSame(DB::table('catalog_rank_masters')->where('status', 'active')->count(),
                DB::table('catalog_gacha_ranks')->where('gacha_id', $gachaId)->whereNotNull('current_video_revision_id')->count());
            $this->mutate('PUT', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/ranks/'.$input['ranks'][0]['rank_id'].'/video', [
                'video_asset_id' => $alternates[0], 'expected_revision' => 2,
            ])->assertOk();
            DB::table('catalog_presentation_assets')->where('public_id', $default)->update(['is_default_rank_video' => false, 'revision' => DB::raw('revision + 1')]);
            DB::table('catalog_presentation_assets')->where('public_id', $alternates[1])->update(['is_default_rank_video' => true, 'revision' => DB::raw('revision + 1')]);
            self::assertSame($alternates[0], app(V2GachaCopyService::class)->projection($gacha['id'], false)['ranks'][0]['video_asset_id']);
            $copy = app(V2GachaCopyService::class)->projection($gacha['id'], true);
            self::assertFalse($copy['use_default_rank_video']);
            $copy['publish_start_at'] = $input['publish_start_at'];
            $created = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $copy)->assertCreated()->json('data');
            self::assertSame($alternates[0], app(V2GachaCopyService::class)->projection($created['id'], false)['ranks'][0]['video_asset_id']);
            DB::table('catalog_presentation_assets')->where('public_id', $alternates[1])->update(['is_default_rank_video' => false, 'revision' => DB::raw('revision + 1')]);
            DB::table('catalog_presentation_assets')->where('public_id', $default)->update(['is_default_rank_video' => true, 'revision' => DB::raw('revision + 1')]);
        }
        $originalIds = array_column(json_decode($before, true), 'id');
        self::assertSame($before, DB::table('catalog_gacha_ranks')->whereIn('id', $originalIds)->orderBy('id')->get()->toJson());
        $pinnedIds = array_column(json_decode($pinned, true), 'id');
        self::assertSame($pinned, DB::table('catalog_gacha_version_ranks')->whereIn('id', $pinnedIds)->orderBy('id')->get()->toJson());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function input(string $type = 'login_daily'): array
    {
        $rankId = DB::table('catalog_rank_masters')->orderBy('id')->value('public_id');
        $prize = ['name' => 'Login prize', 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000007',
            'rank_id' => $rankId, 'exchange_points' => 10, 'cost_price' => 0, 'initial_inventory' => 3,
            'shipping_only' => false, 'percentage' => '0.0000000001'];

        return [
            'gacha_type' => $type, 'title' => 'Login fixture', 'description' => null, 'notices' => null,
            'presentation_asset_id' => '0198a001-0000-7000-8000-000000000005',
            'price_points' => 0, 'minimum_exchange_points' => $type === 'login_daily' ? 10 : null,
            'publish_start_at' => '2026-07-01T00:00:00Z', 'publish_end_at' => null,
            'category_id' => null, 'tag_ids' => [], 'total_count' => null, 'daily_draw_limit' => $type === 'login_daily' ? 1 : 0,
            'audience_code' => 'all_users', 'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1],
            'ranks' => [['rank_id' => $rankId, 'rank_revision_number' => null, 'video_asset_id' => '0198a001-0000-7000-8000-000000000006']],
            'prizes' => [$prize, [...$prize, 'name' => 'Second prize', 'percentage' => '99.9999999999']],
        ];
    }

    private function createPublished(string $type = 'login_daily', ?array $input = null): array
    {
        $gacha = $this->mutate('POST', '/admin/api/v2/catalog/gacha-compositions', $input ?? $this->input($type))->assertCreated()->json('data');
        $this->mutate('POST', '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$gacha['current_version']['id'].'/publish', [
            'expected_revision' => $gacha['current_version']['revision'],
            'expected_gacha_revision' => $gacha['revision'],
        ])->assertOk();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $gacha;
    }

    private function user(?string $qualified = '2026-07-01T00:00:00Z'): User
    {
        $email = 'login-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'first_registration_qualified_at' => $qualified,
            'password_hash' => app(V2PasswordPolicy::class)->hash('test login user password'), 'state' => V2UserState::Active,
        ]);
    }

    private function draw(User $user, array $gacha, string $key): array
    {
        return app(V2DrawService::class)->create($user, $gacha['id'], 1, $key, (string) Str::uuid7());
    }

    private function rejectDraw(User $user, array $gacha, string $key, string $expected): void
    {
        try {
            $this->draw($user, $gacha, $key);
            self::fail('Draw must be rejected.');
        } catch (V2DrawException $exception) {
            self::assertSame($expected, $exception->errorCode);
        }
    }

    private function mutate(string $method, string $uri, array $payload)
    {
        Auth::forgetGuards();
        $csrf = str_repeat('a', 64);
        $request = $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->adminToken)
            ->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7()]);

        return $method === 'PUT' ? $request->putJson($uri, $payload) : $request->postJson($uri, $payload);
    }
}
