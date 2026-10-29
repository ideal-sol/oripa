<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class PrizeShippingConcurrencyTest extends TestCase
{
    public static function competingOperations(): array
    {
        return [
            'automatic exchange and closure' => [['auto', 'closure'], false],
            'two automatic exchanges' => [['auto', 'auto'], false],
            'automatic exchange and manual exchange' => [['auto', 'exchange'], false],
            'automatic exchange and shipping' => [['auto', 'shipping'], false],
            'two shipping requests' => [['shipping', 'shipping-second'], false],
            'normal writer and shipping' => [['grant', 'shipping'], false],
            'normal exchange and shipping' => [['exchange', 'shipping'], false],
            'shipping-only expiration and shipping' => [['expiration', 'shipping'], true],
            'shipping-only expiration and exchange' => [['expiration', 'exchange'], true],
            'shipping-only concurrent expiration' => [['expiration', 'expiration'], true],
        ];
    }

    #[DataProvider('competingOperations')]
    public function test_prize_mutations_of_same_prize_are_serialized(array $operations, bool $shippingOnly): void
    {
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_prize_shipping.address_hmac_key' => 'base64:'.
                base64_encode(str_repeat('p', 32)),
        ]);
        [$userId, $prizeId, $addressId] = $this->fixture($shippingOnly);
        $inventory = DB::table('prize_inventories')->orderBy('id')->get()->toJson();
        $balance = DB::table('wallets')->where('user_id', $userId)->value('free_balance');
        if ($shippingOnly || in_array('auto', $operations, true)) {
            config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
            CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('user_prizes')->where('public_id', $prizeId)->value('storage_expires_at')));
        }
        $token = (string) Str::uuid();
        $startPath = "/tmp/mig052-concurrency-{$token}.start";
        $resultPaths = [
            "/tmp/mig052-concurrency-{$token}-exchange.json",
            "/tmp/mig052-concurrency-{$token}-shipping.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the concurrency process.');
                }
                if ($pid === 0) {
                    $this->runConcurrentOperation(
                        $operation,
                        $userId,
                        $prizeId,
                        $addressId,
                        $startPath,
                        $resultPaths[$index]
                    );
                }
                $children[] = $pid;
            }
            file_put_contents($startPath, 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            $results = array_map(
                static fn (string $path): array => json_decode(
                    file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $resultPaths
            );
            $successCount = in_array($operations, [['expiration', 'expiration'], ['grant', 'shipping'], ['auto', 'auto'], ['auto', 'closure']], true) ? 2 : 1;
            self::assertCount($successCount, array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            ));
            $failure = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            ));
            self::assertCount(2 - $successCount, $failure);
            foreach ($failure as $result) {
                self::assertContains($result['code'], ['PRIZE_NOT_EXCHANGEABLE', 'PRIZE_NOT_SHIPPABLE']);
            }

            DB::reconnect();
            if (in_array('closure', $operations, true)) {
                self::assertSame(0, (int) DB::table('wallets')->where('user_id', $userId)->value('free_balance'));
                self::assertSame(1, DB::table('account_closures')->where('user_id', $userId)->count());
                self::assertContains(DB::table('user_prizes')->where('public_id', $prizeId)->value('status'), ['forfeited', 'converted']);
                self::assertLessThanOrEqual(1, DB::table('prize_exchange_requests')->count());
                self::assertSame(0, app(V2PrizeShippingService::class)->autoExchangeExpired()['processed']);
                return;
            }
            self::assertSame(
                $shippingOnly ? 0 : 1,
                DB::table('prize_exchange_requests')->count()
                    + DB::table('shipping_requests')->count()
            );
            self::assertContains(
                DB::table('user_prizes')->where('public_id', $prizeId)->value('status'),
                $shippingOnly ? ['expired'] : ['converted', 'shipping_requested']
            );
            if ($shippingOnly) {
                self::assertDatabaseHas('user_prizes', ['public_id' => $prizeId, 'status' => 'expired']);
                self::assertSame(1, DB::table('user_prize_status_histories')->where('to_status', 'expired')->count());
                self::assertSame($inventory, DB::table('prize_inventories')->orderBy('id')->get()->toJson());
                self::assertSame($balance, DB::table('wallets')->where('user_id', $userId)->value('free_balance'));
            }
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
            CarbonImmutable::setTestNow();
            @unlink($startPath);
            foreach ($resultPaths as $path) {
                @unlink($path);
            }
        }
    }

    public function test_settings_update_serializes_with_new_shipping_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        config(['cache.default' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_prize_shipping.address_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        [$userId, $prizeId, $addressId] = $this->fixture(false);
        $token = (string) Str::uuid();
        $startPath = '/tmp/shipping-minimum-'.$token.'.start';
        $resultPath = '/tmp/shipping-minimum-'.$token.'.json';
        // Fork with no inherited live PDO transaction; child opens its own connection.
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) $this->runConcurrentOperation('shipping', $userId, $prizeId, $addressId, $startPath, $resultPath);
        self::assertGreaterThan(0, $pid);
        try {
            DB::reconnect();
            DB::beginTransaction();
            DB::table('shipping_minimum_point_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('shipping_minimum_point_settings')->where('id', 1)->update(['minimum_points' => 9007199254740991, 'revision' => 2]);
            file_put_contents($startPath, 'start');
            usleep(200000);
            self::assertFileDoesNotExist($resultPath);
            DB::commit();
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['result' => 'failure', 'code' => 'SHIPPING_MINIMUM_POINTS_NOT_MET'], $result);
            self::assertSame(0, DB::table('shipping_requests')->count());
            self::assertSame('stored', DB::table('user_prizes')->where('public_id', $prizeId)->value('status'));
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
            CarbonImmutable::setTestNow();
            @unlink($startPath); @unlink($resultPath);
        }
    }

    private function runConcurrentOperation(
        string $operation,
        int $userId,
        string $prizeId,
        string $addressId,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            $user = User::query()->findOrFail($userId);
            $service = app(V2PrizeShippingService::class);
            if ($operation === 'closure') {
                app(\App\Domain\Point\Services\V2PointTransactionRunner::class)->run(function () use ($userId): void {
                    $locked = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                    app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($locked, 'admin', 'normal', (string) Str::uuid7());
                });
            } elseif ($operation === 'auto') {
                $result = $service->autoExchangeExpired();
                if ($result['failed'] !== 0) throw new \RuntimeException('Automatic exchange failed');
            } elseif ($operation === 'expiration') {
                $service->expireShippingOnly();
            } elseif ($operation === 'grant') {
                app(V2PointService::class)->grantFree($userId, 1, 'shipping-writer-concurrency');
            } elseif ($operation === 'exchange') {
                $service->exchange(
                    $user,
                    [$prizeId],
                    'concurrent-exchange-0001',
                    (string) Str::uuid7()
                );
            } else {
                $service->createShippingRequest(
                    $user,
                    $addressId,
                    [$prizeId],
                    'concurrent-'.$operation.'-0001',
                    (string) Str::uuid7()
                );
            }
            $result = ['result' => 'success', 'code' => null];
        } catch (V2PrizeShippingException $exception) {
            $result = ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    /** @return array{int, string, string} */
    private function fixture(bool $shippingOnly): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        foreach ($fixture['prizes'] as &$prize) {
            $prize['shipping_only'] = $shippingOnly;
        }
        unset($prize);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $user = User::query()->create([
            'email_display' => 'concurrency@example.test',
            'email_normalized' => 'concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        DB::table('user_phone_numbers')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->getKey(),
            'phone_ciphertext' => Crypt::encryptString('+819012345678'),
            'phone_hmac' => hash('sha256', 'prize-shipping-concurrency-phone'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            100_000,
            'prize-shipping-concurrency-points'
        );
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int =>
                    intdiv(5_000 * $maximum, 1_000_000) + 1
            )
        );
        app(V2DrawService::class)->create(
            $user,
            $fixture['gachas'][0]['public_id'],
            1,
            'prize-shipping-concurrency-draw',
            (string) Str::uuid7()
        );
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, [
            'recipient_name' => '検証用受取人',
            'postal_code' => '000-0000',
            'prefecture' => '検証県',
            'city' => '検証市',
            'street' => '検証町1-2-3',
            'building' => null,
            'phone_number' => '000-0000-0000',
        ], (string) Str::uuid7());

        return [
            $user->id,
            DB::table('user_prizes')->where('user_id', $user->id)->value('public_id'),
            $address['id'],
        ];
    }
}
