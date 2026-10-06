<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Catalog\Services\V2GachaCompositionService;
use App\Domain\Catalog\Services\V2GachaCopyService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\V2TimestampFixture;

final class ZLoginGachaConcurrencyTest extends TestCase
{
    public function test_real_connections_serialize_daily_signup_replay_and_last_inventory(): void
    {
        if (getenv('V2_LOGIN_DRAW_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the explicit isolated login concurrency run.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertMatchesRegularExpression('/^oripa_v2_mig[0-9]{3}_/', DB::connection()->getDatabaseName());
        self::assertTrue(function_exists('pcntl_fork'));
        self::assertSame(0, DB::table('catalog_gachas')->count(), 'Use an empty isolated task database.');
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR));
        $context = $this->adminContext();
        $this->raceExternalIds($context);
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static fn (): int => 1));
        foreach (['daily', 'signup', 'same_key', 'last_inventory'] as $scenario) {
            $input = app(V2GachaCopyService::class)->projection('0198a001-0000-7000-8000-000000000011', true);
            $input = [...$input, 'gacha_type' => $scenario === 'signup' ? 'signup_once' : 'login_daily',
                'price_points' => 0, 'minimum_exchange_points' => $scenario === 'signup' ? null : 0,
                'category_id' => null, 'tag_ids' => [], 'total_count' => null, 'daily_draw_limit' => $scenario === 'signup' ? 0 : 1,
                'audience_code' => 'all_users', 'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1],
                'publish_start_at' => '2026-07-01T00:00:00Z', 'publish_end_at' => null];
            foreach ($input['prizes'] as &$prize) {
                $prize['percentage'] = '50';
                $prize['external_id'] = null;
                $prize['initial_inventory'] = $scenario === 'last_inventory' ? 1 : 3;
            }
            unset($prize);
            $composition = app(V2GachaCompositionService::class);
            $gacha = DB::transaction(fn (): object => $composition->save($composition->validate($input), null, null, $context));
            $version = DB::table('catalog_gacha_versions')->where('gacha_id', $gacha->id)->firstOrFail();
            app(V2CatalogMasterMutationService::class)->publishGachaVersionImmediately($context, $gacha->public_id, $version->public_id,
                'publish-'.$scenario, ['expected_revision' => (int) $version->revision, 'expected_gacha_revision' => (int) $gacha->revision]);
            if (in_array($scenario, ['daily', 'signup'], true)) {
                $this->raceExternalIds($context, $gacha->public_id);
            }
            $firstUser = $this->user();
            $secondUser = $scenario === 'last_inventory' ? $this->user() : $firstUser;
            $workers = [
                [$firstUser->id, $scenario.'-first'],
                [$secondUser->id, $scenario === 'same_key' ? $scenario.'-first' : $scenario.'-second'],
            ];
            $results = $this->race($gacha->public_id, $workers);
            $completed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'completed'));
            self::assertCount($scenario === 'same_key' ? 2 : 1, $completed, json_encode($results));
            if ($scenario === 'same_key') {
                self::assertSame($completed[0]['id'], $completed[1]['id']);
                self::assertNotSame($completed[0]['replay'], $completed[1]['replay']);
            } else {
                $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'completed'));
                self::assertSame($scenario === 'last_inventory' ? 'PRIZE_INVENTORY_UNAVAILABLE' : 'DAILY_DRAW_LIMIT_EXCEEDED', $failed[0]['status']);
            }
            self::assertSame(1, DB::table('draw_requests')->where('gacha_version_id', $version->id)->count());
            self::assertSame(1, (int) DB::table('gacha_draw_states')->where('gacha_id', $gacha->id)->value('sold_count'));
            self::assertSame(1, (int) DB::table('prize_inventories')->whereIn('gacha_version_prize_id',
                DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $version->id)->select('id'))->sum('awarded_count'));
            self::assertSame(0, DB::table('wallets')->whereIn('user_id', [$firstUser->id, $secondUser->id])->count());
        }
    }

    private function raceExternalIds(V2AdminAuthorizationContext $context, string $gachaId = '0198a001-0000-7000-8000-000000000011'): void
    {
        $gacha = DB::table('catalog_gachas')->where('public_id', $gachaId)->firstOrFail();
        $version = DB::table('catalog_gacha_versions')->where('id', $gacha->published_version_id)->firstOrFail();
        $listing = app(\App\Domain\Catalog\Services\V2AdminCatalogReadService::class)
            ->gachaVersionPrizes($context, $gacha->public_id, $version->public_id);
        self::assertGreaterThanOrEqual(2, count($listing['items']));
        $prizes = array_slice($listing['items'], 0, 2);
        $startAt = microtime(true) + 0.5;
        $processes = [];
        DB::disconnect();
        foreach ($prizes as $prize) {
            $path = tempnam(sys_get_temp_dir(), 'external-id-concurrency-');
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1000);
                }
                DB::reconnect();
                try {
                    if ($gacha->gacha_type !== 'standard') {
                        app(V2CatalogMasterMutationService::class)->updateGachaRankPrize(
                            $context, $gacha->public_id, $version->public_id, $prize['rank']['id'], $prize['id'], (string) Str::uuid7(),
                            ['external_id' => 'RACE-CARD', 'expected_revision' => $prize['revision'],
                                'expected_version_revision' => $listing['version_revision']]
                        );
                    } else {
                        app(V2CatalogMasterMutationService::class)->updateGachaRankPrize(
                            $context, $gacha->public_id, $version->public_id, $prize['rank']['id'], $prize['id'], (string) Str::uuid7(), [
                                'external_id' => 'RACE-CARD', 'presentation_asset_id' => $prize['presentation_asset']['id'],
                                'name' => $prize['name'], 'exchange_points' => $prize['exchange_points'],
                                'shipping_only' => $prize['shipping_only'], 'cost_price' => $prize['cost_price'],
                                'is_active' => $prize['is_visible'], 'total_inventory' => $prize['total_inventory'],
                                'expected_revision' => $prize['revision'], 'expected_version_revision' => $listing['version_revision'],
                            ]
                        );
                    }
                    $outcome = 'completed';
                } catch (\App\Domain\Catalog\Exceptions\V2CatalogException $exception) {
                    $outcome = $exception->errorCode;
                } catch (\Throwable $exception) {
                    $outcome = get_class($exception);
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            $processes[] = [$processId, $path];
        }
        $outcomes = [];
        foreach ($processes as [$processId, $path]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcomes[] = file_get_contents($path);
            unlink($path);
        }
        DB::reconnect();
        sort($outcomes);
        self::assertSame(['CATALOG_PRIZE_EXTERNAL_ID_CONFLICT', 'completed'], $outcomes);
        self::assertSame(1, DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('relation.gacha_version_id', $version->id)->where('prize.external_id', 'RACE-CARD')->count());
    }

    private function race(string $gachaId, array $workers): array
    {
        $startAt = microtime(true) + 0.5;
        $processes = [];
        DB::disconnect();
        foreach ($workers as [$userId, $key]) {
            $path = tempnam(sys_get_temp_dir(), 'login-concurrency-');
            $processId = pcntl_fork();
            if ($processId === -1) {
                self::fail('Could not fork a login Draw worker.');
            }
            if ($processId === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1000);
                }
                DB::reconnect();
                try {
                    $response = app(V2DrawService::class)->create(User::query()->findOrFail($userId), $gachaId, 1, $key, (string) Str::uuid7());
                    $result = ['status' => 'completed', 'id' => $response['id'], 'replay' => $response['idempotent_replay']];
                } catch (V2DrawException $exception) {
                    $result = ['status' => $exception->errorCode];
                } catch (\Throwable $exception) {
                    $result = ['status' => get_class($exception)];
                }
                file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            $processes[] = [$processId, $path];
        }
        $results = [];
        foreach ($processes as [$processId, $path]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $results[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            unlink($path);
        }
        DB::reconnect();

        return $results;
    }

    private function user(): User
    {
        $email = 'login-concurrency-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'first_registration_qualified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic concurrency password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function adminContext(): V2AdminAuthorizationContext
    {
        $publicId = (string) Str::uuid7();
        $email = 'login-concurrency-admin@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $publicId, 'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic concurrency admin password'), 'role' => 'owner', 'state' => 'active',
        ]);
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId(app(V2SessionPolicy::class)->issueOpaqueSessionId());
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $sessionHash, 'admin_id' => $adminId, 'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false,
            'created_at' => $created, 'last_activity_at' => now(), 'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => $created->copy()->addHours(8),
        ]));

        return new V2AdminAuthorizationContext($adminId, $publicId, V2AdminRole::Owner, $sessionHash, hash('sha256', $sessionHash), (string) Str::uuid7());
    }
}
