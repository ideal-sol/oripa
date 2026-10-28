<?php

namespace Tests\V2\Fixtures;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Payment\V2\Services\V2TechcoinIdentity;
use App\Domain\Payment\V2\Services\V2TechcoinPaymentService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait TechcoinScenario
{
    private function configureTechcoin(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_payment.methods_enabled' => 'netridecash', 'v2_payment.mock_driver' => false,
            'v2_techcoin.client_ip' => '0123456789', 'v2_techcoin.endpoint' => 'https://secure.tc-site.com/access',
            'v2_techcoin.payment_code' => '10055', 'v2_techcoin.kickback_path_token' => str_repeat('synthetic', 5),
            'v2_techcoin.kickback_allowed_ips' => '', 'v2_techcoin.mode' => 'test',
            'v2_fincode.storefront_origin' => 'https://storefront.example.test',
            'v2_fincode.admin_origin' => 'https://admin.example.test',
            'v2_identity.public_origin' => 'https://example.test']);
    }

    private function techcoinUser(): User
    {
        $email = 'techcoin-'.Str::uuid().'@example.test';
        return SmsVerifiedUser::create(['public_id' => (string) Str::uuid7(), 'email_display' => $email,
            'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('valid synthetic password'),
            'state' => V2UserState::Active, 'display_name' => 'Synthetic Techcoin']);
    }

    private function techcoinPlan(int $amount = 3000, int $bonus = 100): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId(['public_id' => (string) Str::uuid7(), 'code' => 'tec-'.Str::uuid(),
            'version_no' => 1, 'name' => 'Synthetic coins', 'amount' => $amount, 'paid_point_amount' => $amount,
            'free_point_amount' => $bonus, 'currency' => 'JPY', 'status' => 'published']);
        return DB::table('point_purchase_plans')->where('id', $id)->firstOrFail();
    }

    private function techcoinPayment(?User $user = null, ?object $plan = null, ?string $key = null): array
    {
        $user ??= $this->techcoinUser(); $plan ??= $this->techcoinPlan(); $key ??= (string) Str::uuid();
        $result = app(V2TechcoinPaymentService::class)->start($user, $plan->public_id, $key);
        $row = DB::table('payments')->where('public_id', $result['id'])->firstOrFail();
        $input = ['cookie' => V2TechcoinIdentity::cookie($row->public_id), 'sendid' => V2TechcoinIdentity::sendid($user->public_id),
            'price' => (string) $row->amount, 'rel' => '1', 'payment_code' => '10055'];
        return [$user, $row, $input, $result];
    }
}
