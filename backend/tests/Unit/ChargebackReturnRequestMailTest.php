<?php

namespace Tests\Unit;

use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Mail\ChargebackReturnRequestMail;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargebackReturnRequestMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_contains_return_request_information(): void
    {
        $user = User::factory()->create(['name' => '山田 太郎', 'email' => 'taro@example.test']);
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $action = $this->createReturnRequestedAction($reversal, 'テスト景品A');

        $mail = new ChargebackReturnRequestMail(
            $reversal->load('user'),
            collect([$action->load('userPrize.prize')]),
        );

        $body = $mail->render();

        $this->assertStringContainsString('山田 太郎 様', $body);
        $this->assertStringContainsString('チャージバック', $body);
        $this->assertStringContainsString('テスト景品A', $body);
        $this->assertStringContainsString('payment ID: #'.$payment->id, $body);
        $this->assertStringContainsString('reversal ID: #'.$reversal->id, $body);
        $this->assertStringContainsString('重要なお知らせ', $body);
    }

    private function createPayment(User $user): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mail-payment-'.uniqid(),
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

    private function createReturnRequestedAction(PaymentReversal $reversal, string $prizeName): PaymentReversalPrizeAction
    {
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->for($gacha)->create();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => $prizeName]);

        $userPrize = UserPrize::query()->create([
            'user_id' => $reversal->user_id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $this->createDrawResultId($reversal->user_id, $gacha, $rank, $prize),
            'status' => UserPrizeStatus::ShippingRequested,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addMonth(),
        ]);

        return PaymentReversalPrizeAction::query()->create([
            'payment_reversal_id' => $reversal->id,
            'user_prize_id' => $userPrize->id,
            'shipping_item_id' => null,
            'action_type' => PaymentReversalPrizeActionType::ReturnRequested,
            'previous_user_prize_status' => UserPrizeStatus::Shipped->value,
            'status' => PaymentReversalPrizeActionStatus::Pending,
        ]);
    }

    private function createDrawResultId(int $userId, Gacha $gacha, GachaRank $rank, GachaPrize $prize): int
    {
        $version = \App\Models\GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);
        $stage = \App\Models\GachaProbabilityVersionStage::query()->create([
            'probability_version_id' => $version->id,
            'stage_key' => 'default',
            'name' => 'Default',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);
        $request = \App\Models\DrawRequest::query()->create([
            'user_id' => $userId,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'mail-draw-'.uniqid(),
            'status' => \App\Domain\Gacha\Enums\DrawRequestStatus::Completed,
            'consumed_point_total' => 100,
        ]);

        return \App\Models\DrawResult::query()->create([
            'draw_request_id' => $request->id,
            'user_id' => $userId,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $request->id,
            'rank_id' => $rank->id,
            'prize_id' => $prize->id,
            'result_type' => \App\Domain\Gacha\Enums\DrawResultType::Prize,
            'consumed_point' => 100,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ])->id;
    }
}
