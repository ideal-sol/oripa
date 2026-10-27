<?php

namespace Tests\V2\Fixtures;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Models\V2\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait PaidyFixtures
{
    protected static function resetPaidyTestDatabase(): void
    {
        // HTTP boundary tests commit real transactions. Restore the disposable
        // test schema after the class instead of deleting immutable money rows.
        $application = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! $application->environment('testing') || config('database.default') !== 'pgsql') {
            throw new \LogicException('Paidy fixture cleanup requires isolated PostgreSQL testing.');
        }
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
    }

    protected function quiescePaidyFixtureJobs(): void
    {
        // Synthetic test database only: keep prior cases out of worker batches.
        DB::table('outbox_messages')->where('topic', 'payment.paidy')->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'delivered', 'delivered_at' => now(), 'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null]);
    }

    protected function configurePaidy(string $mode = 'test'): void
    {
        config([
            'v2_identity.origins.user' => 'https://example.test', 'mail.default' => 'array',
            'v2_payment.methods_enabled' => 'paidy', 'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_paidy.api_base_url' => 'https://api.paidy.com', 'v2_paidy.api_version' => '2018-04-10',
            'v2_paidy.public_key' => 'pk_synthetic_public_key', 'v2_paidy.secret_key' => 'sk_synthetic_server_key',
            'v2_paidy.store_name' => 'Synthetic store', 'v2_paidy.mode' => $mode,
            'v2_paidy.webhook_path_token' => str_repeat('p', 32), 'v2_paidy.worker_enabled' => false,
        ]);
        Http::swap(new Factory()); Http::preventStrayRequests();
    }

    protected function paidyUser(): User
    {
        $email = 'synthetic-'.Str::uuid().'@example.test';
        return SmsVerifiedUser::create(['email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => password_hash('SyntheticPassword123', PASSWORD_ARGON2ID), 'state' => V2UserState::Active, 'display_name' => 'Synthetic']);
    }

    protected function paidyPlan(int $amount = 1000): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId(['public_id' => (string) Str::uuid7(), 'code' => 'pay4-'.Str::uuid(),
            'version_no' => 1, 'name' => 'Synthetic coins', 'amount' => $amount, 'paid_point_amount' => $amount,
            'free_point_amount' => 100, 'currency' => 'JPY', 'status' => 'published']);
        return DB::table('point_purchase_plans')->where('id', $id)->firstOrFail();
    }

    protected function paidyPayment(): array
    {
        $user = $this->paidyUser(); $plan = $this->paidyPlan();
        $result = app(V2PaidyPaymentService::class)->start($user, $plan->public_id, (string) Str::uuid());
        return [$user, DB::table('payments')->where('public_id', $result['id'])->firstOrFail(), $result];
    }

    protected function paidyObject(object $payment, string $status = 'AUTHORIZED', ?string $id = null): array
    {
        return ['id' => $id ?? 'pay_'.str_replace('-', '', $payment->public_id), 'amount' => (int) $payment->amount,
            'currency' => 'JPY', 'test' => config('v2_paidy.mode') === 'test', 'status' => $status,
            'order' => ['order_ref' => $payment->public_id],
            'captures' => $status === 'CLOSED' ? [['id' => 'cap_synthetic', 'amount' => (int) $payment->amount,
                'created_at' => now()->utc()->startOfSecond()->format('Y-m-d\TH:i:s\Z')]] : [], 'refunds' => []];
    }

    protected function authorizePaidy(User $user, object $payment, string $id): array
    {
        return app(V2PaidyPaymentService::class)->authorize($user, $payment->public_id, ['paidy_payment_id' => $id], (string) Str::uuid());
    }
}
