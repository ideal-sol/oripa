<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2StripeClient;
use App\Domain\Payment\V2\Services\V2StripePaymentService;
use App\Domain\Payment\V2\Services\V2StripeReconciliationService;
use App\Domain\Payment\V2\Services\V2StripeWorker;
use App\Domain\Payment\V2\Services\V2UnivapayPaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\V2\Fixtures\UnivapayFixtures;

final class ZStripeConcurrencyTest extends TestCase
{
    use UnivapayFixtures;

    protected function tearDown(): void
    {
        try {
            DB::reconnect();
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        } finally {
            parent::tearDown();
        }
    }

    public function test_checkout_and_settlement_serialize_with_other_providers_and_stripe_workers(): void
    {
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureUnivapay();
        config(['v2_stripe.mode' => 'test', 'v2_stripe.secret_key' => 'rk_test_'.'synthetic',
            'v2_stripe.publishable_key' => 'pk_test_'.'synthetic', 'v2_stripe.webhook_secret' => 'whsec_'.'synthetic']);
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $univapay = app(V2UnivapayPaymentService::class)->start($user, $plan->public_id, (string) Str::uuid());
        $other = DB::table('payments')->where('public_id', $univapay['id'])->sole();
        $otherCharge = $this->charge($other);
        $this->fakeCharge($otherCharge);
        $client = new class extends V2StripeClient {
            public array $open = [];

            public function create(array $parameters, string $key): array
            {
                if (DB::transactionLevel()) throw new \LogicException('Checkout requested under a database lock');
                return $this->open = ['id' => 'cs_test_Race', 'mode' => 'payment', 'livemode' => false,
                    'currency' => 'jpy', 'amount_total' => 1000, 'status' => 'open', 'payment_status' => 'unpaid',
                    'client_reference_id' => $parameters['client_reference_id'], 'metadata' => $parameters['metadata'],
                    'expires_at' => $parameters['expires_at'], 'client_secret' => 'cs_test_Race_secret_fixture'];
            }

            public function expire(string $id): void {}

            public function session(string $id): array
            {
                if (DB::transactionLevel()) throw new \LogicException('Lookup requested under a database lock');
                return [...$this->open, 'status' => 'complete', 'payment_status' => 'paid',
                    'payment_intent' => ['id' => 'pi_Race', 'amount_received' => 1000, 'currency' => 'jpy',
                        'status' => 'succeeded', 'livemode' => false, 'metadata' => $this->open['metadata'],
                        'latest_charge' => ['id' => 'ch_Race', 'created' => 1795737600, 'payment_intent' => 'pi_Race',
                            'payment_method_details' => ['type' => 'card']]]];
            }
        };
        app()->instance(V2StripeClient::class, $client);
        $stripe = app(V2StripePaymentService::class)->start($user, $plan->public_id, 'stripe-race');
        $payment = DB::table('payments')->where('public_id', $stripe['id'])->sole();
        $worker = app(V2StripeWorker::class);
        $worker->receive(['id' => 'evt_Race', 'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => ['id' => $client->open['id']]]]);
        $this->travel(6)->minutes();
        $sync = app(V2StripeReconciliationService::class);
        $jobs = [
            fn () => $sync->operation($user, $stripe['id'], 'sync', null),
            fn () => $sync->operation($user, $stripe['id'], 'cancel', 'cancel-race'),
            fn () => $worker->work(10),
            fn () => $worker->reconcile(),
            fn () => app(V2UnivapayPaymentService::class)->reconcile($otherCharge['id'], $other->id),
            fn () => app(V2UnivapayPaymentService::class)->start($user, $plan->public_id, 'parallel-purchase'),
        ];
        DB::disconnect();
        $start = microtime(true) + 0.5;
        $children = [];
        foreach ($jobs as $job) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::statement("SET statement_timeout = '20s'");
                    while (microtime(true) < $start) usleep(1000);
                    $job();
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $error) {
                    fwrite(STDERR, $error::class.PHP_EOL);
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            $results[] = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
        }
        self::assertNotContains(false, $results);
        DB::reconnect();
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $other->id)->count());
        self::assertSame('delivered', DB::table('outbox_messages')->where('deduplication_key', 'stripe.receipt:evt_Race')->value('status'));
    }
}
