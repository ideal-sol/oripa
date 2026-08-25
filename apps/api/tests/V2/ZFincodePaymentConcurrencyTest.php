<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Services\V2FincodeCardService;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2FincodeWebhookService;
use App\Models\V2\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class ZFincodePaymentConcurrencyTest extends TestCase
{
    public function test_fincode_concurrency_invariants_hold(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for fincode concurrency verification.');
        }
        $this->configureBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);

        try {
            $this->assertConcurrentFourthCardRejected();
            $this->assertConcurrentKonbiniCreationRejected();
            $this->assertConcurrentWebhookExactlyOnce();
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
        }
    }

    private function assertConcurrentFourthCardRejected(): void
    {
        $this->fakeFincode();
        $user = $this->user('concurrent-card');
        $cards = app(V2FincodeCardService::class);
        foreach (range(1, 2) as $index) {
            $intent = $cards->reserveRegistration($user, 'existing-card-'.$index);
            $cards->completeRegistration($user, $intent['id'], 'existing_card_'.$index);
        }
        $userId = $user->id;
        $results = $this->parallel([
            'card-a' => static fn (): array => app(V2FincodeCardService::class)
                ->reserveRegistration(User::query()->findOrFail($userId), 'concurrent-card-a'),
            'card-b' => static fn (): array => app(V2FincodeCardService::class)
                ->reserveRegistration(User::query()->findOrFail($userId), 'concurrent-card-b'),
        ]);

        self::assertSame(['CARD_LIMIT_REACHED', 'success'], $this->outcomes($results));
        self::assertSame(3, DB::table('fincode_cards')->where('user_id', $userId)->count()
            + DB::table('fincode_card_registration_intents')
                ->where('user_id', $userId)->where('status', 'reserved')->count());
    }

    private function assertConcurrentKonbiniCreationRejected(): void
    {
        $this->fakeFincode();
        $user = $this->user('concurrent-konbini');
        $plan = $this->plan('concurrent-konbini');
        $userId = $user->id;
        $planPublicId = $plan->public_id;
        $results = $this->parallel([
            'konbini-a' => static fn (): array => app(V2FincodePaymentService::class)->start(
                User::query()->findOrFail($userId),
                $planPublicId,
                'konbini',
                'concurrent-konbini-a'
            ),
            'konbini-b' => static fn (): array => app(V2FincodePaymentService::class)->start(
                User::query()->findOrFail($userId),
                $planPublicId,
                'konbini',
                'concurrent-konbini-b'
            ),
        ]);

        self::assertSame(['KONBINI_UNPAID_LIMIT_REACHED', 'success'], $this->outcomes($results));
        self::assertSame(1, DB::table('payments')
            ->where('user_id', $userId)->where('payment_method', 'konbini')->count());
    }

    private function assertConcurrentWebhookExactlyOnce(): void
    {
        $this->fakeFincode('CAPTURED');
        $user = $this->user('concurrent-webhook');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan('concurrent-webhook')->public_id,
            'paypay',
            'concurrent-webhook-start'
        );
        $paymentRow = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $raw = json_encode([
            'event' => 'payments.paypay.complete',
            'order_id' => $paymentRow->provider_payment_id,
            'pay_type' => 'Paypay',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        $results = $this->parallel([
            'webhook-a' => static function () use ($raw, $paymentRow): array {
                $result = app(V2FincodeWebhookService::class)
                    ->process($raw, 'test-webhook-signature');

                return [...$result, 'grant_count' => DB::table('payment_point_grants')
                    ->where('payment_id', $paymentRow->id)->count()];
            },
            'webhook-b' => static function () use ($raw, $paymentRow): array {
                $result = app(V2FincodeWebhookService::class)
                    ->process($raw, 'test-webhook-signature');

                return [...$result, 'grant_count' => DB::table('payment_point_grants')
                    ->where('payment_id', $paymentRow->id)->count()];
            },
        ]);

        self::assertSame(['success', 'success'], $this->outcomes($results));
        self::assertSame(['processed', 'processed'], array_column($results, 'status'));
        self::assertSame([1, 1], array_column($results, 'grant_count'));
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $paymentRow->id)->count());
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'coin.purchase.completed:'.$paymentRow->public_id)->count());
    }

    /** @param array<string, callable(): array<string, mixed>> $workers */
    private function parallel(array $workers): array
    {
        $token = (string) Str::uuid7();
        $startAt = microtime(true) + 0.3;
        $children = [];
        $paths = [];
        DB::disconnect();

        foreach ($workers as $name => $worker) {
            $path = sys_get_temp_dir().'/mig079-'.$token.'-'.$name.'.json';
            $paths[] = $path;
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to start a fincode concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                try {
                    $value = $worker();
                    $result = [
                        ...$value,
                        'outcome' => 'success',
                    ];
                } catch (Throwable $exception) {
                    $result = [
                        'outcome' => property_exists($exception, 'errorCode')
                            ? $exception->errorCode
                            : $exception::class,
                    ];
                }
                file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();
        $results = array_map(
            static fn (string $path): array => json_decode(
                file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR
            ),
            $paths
        );
        foreach ($paths as $path) {
            @unlink($path);
        }

        return $results;
    }

    /** @param array<int, array{outcome: string}> $results */
    private function outcomes(array $results): array
    {
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);

        return $outcomes;
    }

    private function configureBoundary(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_fincode.enabled' => true,
            'v2_fincode.base_url' => 'https://api.test.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_test_secret-not-production',
            'v2_fincode.public_api_key' => 'p_test_public-key',
            'v2_fincode.webhook_signature' => 'test-webhook-signature',
            'v2_fincode.platform_origin' => 'https://api.luxe-pack.biz',
            'v2_fincode.storefront_origin' => 'https://luxe-pack.biz',
        ]);
    }

    private function fakeFincode(string $retrievedStatus = 'AWAITING_CUSTOMER_PAYMENT'): void
    {
        Http::swap(new Factory());
        Http::fake(function (Request $request) use ($retrievedStatus) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/customers')) {
                return Http::response(['id' => $request->data()['id']], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/cards/')) {
                $path = (string) parse_url($url, PHP_URL_PATH);

                return Http::response([
                    'id' => basename($path),
                    'brand' => 'VISA',
                    'customer_id' => basename(dirname(dirname($path))),
                    'card_no' => '************4242',
                    'expire' => '3012',
                ], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/sessions')) {
                return Http::response([
                    'id' => 's_'.Str::lower((string) Str::ulid()),
                    'link_url' => 'https://pay.test.fincode.jp/session/'.Str::lower((string) Str::ulid()),
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/v1/payments/')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response([
                    'id' => basename((string) parse_url($url, PHP_URL_PATH)),
                    'pay_type' => $query['pay_type'] ?? null,
                    'status' => $retrievedStatus,
                    'amount' => '1000',
                    'tax' => '0',
                    'transaction_date' => '2026/08/24 12:00:00.000',
                ], 200);
            }

            return Http::response([], 500);
        });
    }

    private function plan(string $name): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'fincode-'.$name.'-'.Str::uuid(),
            'version_no' => 1,
            'name' => 'fincode concurrency plan',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('point_purchase_plans')->where('id', $id)->firstOrFail();
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'email_display' => $name.'-'.Str::uuid().'@example.test',
            'email_normalized' => $name.'-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
    }
}
