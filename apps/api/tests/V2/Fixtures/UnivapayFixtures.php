<?php

namespace Tests\V2\Fixtures;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Payment\V2\Services\V2UnivapayPaymentService;
use App\Models\V2\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait UnivapayFixtures
{
    protected function configureUnivapay(string $mode = 'test'): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode(['mode' => $mode])), '+/', '-_'), '=').'.c3ludGhldGlj';
        config([
            'v2_identity.origins.user' => 'https://example.test',
            'v2_payment.credit_card_provider' => 'univapay', 'v2_payment.methods_enabled' => 'credit_card',
            'v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_fincode.storefront_origin' => 'https://example.test', 'v2_fincode.admin_origin' => 'https://admin.example.test',
            'v2_univapay.api_base_url' => 'https://api.univapay.com', 'v2_univapay.store_id' => '0198a001-0000-7000-8000-000000000001',
            'v2_univapay.form_app_token' => $jwt, 'v2_univapay.api_app_token' => $jwt,
            'v2_univapay.api_secret' => 'synthetic-api-secret', 'v2_univapay.mode' => $mode,
            'v2_univapay.webhook_path_token' => str_repeat('p', 32), 'v2_univapay.webhook_auth_token' => str_repeat('a', 32),
            'v2_univapay.notify_allowed_ips' => '', 'v2_univapay.worker_enabled' => false,
        ]);
        Http::swap(new Factory());
        Http::preventStrayRequests();
    }

    protected function univapayUser(): User
    {
        $email = 'synthetic-'.Str::uuid().'@example.test';
        return SmsVerifiedUser::create(['public_id' => (string) Str::uuid7(), 'email_display' => $email,
            'email_normalized' => $email, 'email_verified_at' => now(), 'password_hash' => password_hash('SyntheticPassword123', PASSWORD_ARGON2ID),
            'state' => V2UserState::Active, 'display_name' => 'Synthetic']);
    }

    protected function univapayPlan(int $amount = 1000): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId(['public_id' => (string) Str::uuid7(), 'code' => 'pay2-'.Str::uuid(),
            'version_no' => 1, 'name' => 'Synthetic plan', 'amount' => $amount, 'paid_point_amount' => $amount,
            'free_point_amount' => 100, 'currency' => 'JPY', 'status' => 'published']);
        return DB::table('point_purchase_plans')->where('id', $id)->firstOrFail();
    }

    protected function univapayPayment(int $amount = 1000): array
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan($amount);
        $result = app(V2UnivapayPaymentService::class)->start($user, $plan->public_id, (string) Str::uuid());
        return [$user, DB::table('payments')->where('public_id', $result['id'])->firstOrFail(), $result];
    }

    protected function charge(object $payment, string $status = 'successful', ?string $id = null): array
    {
        return ['id' => $id ?? (string) Str::uuid(), 'store_id' => config('v2_univapay.store_id'),
            'transaction_token_type' => 'one_time', 'requested_amount' => (int) $payment->amount, 'requested_currency' => 'JPY',
            'charged_amount' => (int) $payment->amount, 'charged_currency' => 'JPY', 'mode' => config('v2_univapay.mode'), 'status' => $status,
            'metadata' => ['oripa-payment-id' => $payment->public_id, 'oripa-member' => User::query()->findOrFail($payment->user_id)->public_id],
            'created_on' => now()->utc()->startOfSecond()->toIso8601String()];
    }

    protected function fakeCharge(array $charge, int $httpStatus = 200): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.univapay.com/*' => Http::response($charge, $httpStatus)]);
    }
}
