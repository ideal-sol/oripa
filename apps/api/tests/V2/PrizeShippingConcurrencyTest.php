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
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class PrizeShippingConcurrencyTest extends TestCase
{
    public function test_exchange_and_shipping_of_same_prize_are_serialized(): void
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
        [$userId, $prizeId, $addressId] = $this->fixture();
        $token = (string) Str::uuid();
        $startPath = "/tmp/mig052-concurrency-{$token}.start";
        $resultPaths = [
            "/tmp/mig052-concurrency-{$token}-exchange.json",
            "/tmp/mig052-concurrency-{$token}-shipping.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach (['exchange', 'shipping'] as $index => $operation) {
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
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            ));
            $failure = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            ));
            self::assertCount(1, $failure);
            self::assertContains(
                $failure[0]['code'],
                ['PRIZE_NOT_EXCHANGEABLE', 'PRIZE_NOT_SHIPPABLE']
            );

            DB::reconnect();
            self::assertSame(
                1,
                DB::table('prize_exchange_requests')->count()
                    + DB::table('shipping_requests')->count()
            );
            self::assertContains(
                DB::table('user_prizes')->where('public_id', $prizeId)->value('status'),
                ['converted', 'shipping_requested']
            );
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
            if ($operation === 'exchange') {
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
                    'concurrent-shipping-0001',
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
    private function fixture(): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $user = User::query()->create([
            'email_display' => 'concurrency@example.test',
            'email_normalized' => 'concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            100_000,
            now()->addYear(),
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
