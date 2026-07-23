<?php

namespace Tests\Unit;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentReturnRequestMailService;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Mail\ChargebackReturnRequestMail;
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
use RuntimeException;
use Tests\TestCase;

class PaymentReturnRequestMailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_one_mail_for_multiple_return_requested_actions_and_marks_sent(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'return@example.test']);
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $first = $this->createReturnRequestedAction($reversal, '返送景品A');
        $second = $this->createReturnRequestedAction($reversal, '返送景品B');

        $summary = app(PaymentReturnRequestMailService::class)->sendForReversal($reversal);

        $this->assertTrue($summary['sent']);
        $this->assertSame(2, $summary['sent_count']);
        Mail::assertSent(ChargebackReturnRequestMail::class, 1);
        Mail::assertSent(ChargebackReturnRequestMail::class, function (ChargebackReturnRequestMail $mail): bool {
            return $mail->hasTo('return@example.test') && $mail->actions->count() === 2;
        });
        $this->assertNotNull($first->refresh()->mail_sent_at);
        $this->assertNotNull($second->refresh()->mail_sent_at);
        $this->assertNull($first->mail_last_error);
    }

    public function test_it_does_not_send_already_sent_actions_again(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $this->createReturnRequestedAction($reversal, '送信済み景品', ['mail_sent_at' => now()]);

        $summary = app(PaymentReturnRequestMailService::class)->sendForReversal($reversal);

        $this->assertFalse($summary['attempted']);
        $this->assertSame(1, $summary['skipped_count']);
        Mail::assertNothingSent();
    }

    public function test_it_records_error_when_mail_send_fails_without_throwing(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $action = $this->createReturnRequestedAction($reversal, '失敗景品');

        $summary = app(PaymentReturnRequestMailService::class)->sendForReversal($reversal);

        $this->assertTrue($summary['attempted']);
        $this->assertFalse($summary['sent']);
        $this->assertSame(1, $summary['failed_count']);
        $this->assertNull($action->refresh()->mail_sent_at);
        $this->assertNotNull($action->mail_last_attempted_at);
        $this->assertStringContainsString('smtp down', $action->mail_last_error);
    }

    private function createPayment(User $user): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'return-mail-payment-'.uniqid(),
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
            'idempotency_key' => 'return-mail-draw-'.uniqid(),
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
