<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Mail\ChargebackReturnRequestMail;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaProbabilityVersionStage;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentReturnRequestMailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_return_request_mail(): void
    {
        Mail::fake();
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'return-api@example.test']);
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $first = $this->createReturnRequestedAction($reversal, 'API返送景品A');
        $second = $this->createReturnRequestedAction($reversal, 'API返送景品B');

        $this->postJson("/admin/api/payment-reversals/{$reversal->id}/send-return-request-mail")
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.sent_count', 2)
            ->assertJsonPath('payment_reversal.data.id', $reversal->id);

        Mail::assertSent(ChargebackReturnRequestMail::class, 1);
        $this->assertNotNull($first->refresh()->mail_sent_at);
        $this->assertNotNull($second->refresh()->mail_sent_at);
    }

    public function test_send_return_request_mail_rejects_reversal_without_return_requested_actions(): void
    {
        Mail::fake();
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);

        $this->postJson("/admin/api/payment-reversals/{$reversal->id}/send-return-request-mail")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_reversal');

        Mail::assertNothingSent();
    }

    public function test_send_return_request_mail_does_not_resend_already_sent_actions(): void
    {
        Mail::fake();
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $this->createReturnRequestedAction($reversal, '送信済み景品', ['mail_sent_at' => now()]);

        $this->postJson("/admin/api/payment-reversals/{$reversal->id}/send-return-request-mail")
            ->assertOk()
            ->assertJsonPath('data.attempted', false)
            ->assertJsonPath('data.skipped_count', 1);

        Mail::assertNothingSent();
    }

    public function test_non_admin_cannot_send_return_request_mail(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);

        $this->postJson("/admin/api/payment-reversals/{$reversal->id}/send-return-request-mail")
            ->assertForbidden();
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    private function createPayment(User $user): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'return-request-api-'.uniqid(),
            'status' => PaymentStatus::Chargeback,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'paid_at' => now()->subDay(),
            'chargeback_at' => now(),
        ]);
    }

    private function createReversal(Payment $payment): PaymentReversal
    {
        return PaymentReversal::query()->create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'type' => PaymentReversalType::Chargeback,
            'status' => PaymentReversalStatus::Completed,
            'payment_amount' => $payment->amount,
            'paid_point_amount' => $payment->paid_point_amount,
            'free_point_amount' => $payment->free_point_amount,
            'occurred_at' => now(),
        ]);
    }

    private function createReturnRequestedAction(PaymentReversal $reversal, string $prizeName, array $attributes = []): PaymentReversalPrizeAction
    {
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->for($gacha)->create();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => $prizeName]);
        $version = GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);
        $stage = GachaProbabilityVersionStage::query()->create([
            'probability_version_id' => $version->id,
            'stage_key' => 'default',
            'name' => 'Default',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);
        $request = DrawRequest::query()->create([
            'user_id' => $reversal->user_id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'return-api-draw-'.uniqid(),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => 100,
        ]);
        $drawResult = DrawResult::query()->create([
            'draw_request_id' => $request->id,
            'user_id' => $reversal->user_id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $request->id,
            'rank_id' => $rank->id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => 100,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);
        $userPrize = UserPrize::query()->create([
            'user_id' => $reversal->user_id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => UserPrizeStatus::ShippingRequested,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addMonth(),
        ]);

        return PaymentReversalPrizeAction::query()->create(array_merge([
            'payment_reversal_id' => $reversal->id,
            'user_prize_id' => $userPrize->id,
            'shipping_item_id' => null,
            'action_type' => PaymentReversalPrizeActionType::ReturnRequested,
            'previous_user_prize_status' => UserPrizeStatus::Shipped->value,
            'status' => PaymentReversalPrizeActionStatus::Pending,
        ], $attributes));
    }
}
