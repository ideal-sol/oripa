<?php

namespace App\Domain\Payment\V2\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2PaymentReceiptService
{
    public function snapshot(int $paymentId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment receipt requires the settlement transaction.');
        }
        $payment = DB::table('payments')->where('id', $paymentId)->firstOrFail();
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->firstOrFail();
        $rank = DB::table('member_rank_purchase_bonuses')->where('payment_id', $paymentId)->first();
        $wallet = DB::table('wallets')->where('user_id', $payment->user_id)->firstOrFail();
        $paid = (int) $payment->paid_point_amount;
        $product = (int) $payment->free_point_amount;
        $limited = (int) $payment->limited_bonus_point_amount;
        $rankAmount = (int) ($rank?->bonus_amount ?? 0);
        $lines = ['決済番号：'.$payment->public_id, '有償コイン：'.number_format($paid)];
        foreach (['商品ボーナス（無償）' => $product, '期間限定ボーナス（無償）' => $limited, '購入ボーナス（無償）' => $rankAmount] as $label => $amount) {
            if ($amount > 0) {
                $lines[] = $label.'：'.number_format($amount);
            }
        }
        $lines[] = '付与コイン合計：'.number_format($paid + $product + $limited + $rankAmount);
        $paidExpiry = DB::table('point_lots')->where('grant_operation_id', $grant->point_operation_id)
            ->where('point_type', 'paid')->value('expire_at');
        $lines[] = '有償コインの有効期限：'.($paidExpiry === null ? 'なし'
            : CarbonImmutable::parse($paidExpiry, 'UTC')->setTimezone('Asia/Tokyo')->format('Y年n月j日 H:i:s').'（日本時間）');
        $lots = DB::table('point_lots')->whereIn('grant_operation_id', array_filter([$grant->point_operation_id, $rank?->point_operation_id]))
            ->where('point_type', 'free')->orderBy('expire_at')->get();
        foreach ($lots as $lot) {
            $lines[] = '無償コイン '.number_format((int) $lot->granted_amount).' の有効期限：'
                .CarbonImmutable::parse($lot->expire_at)->setTimezone('Asia/Tokyo')->format('Y年n月j日 H:i').'（日本時間）';
        }
        $lines[] = '購入後のコイン残高：'.number_format((int) $wallet->paid_balance + (int) $wallet->free_balance);
        $values = [
            'purchase_plan' => $payment->plan_name_snapshot,
            'purchase_amount' => number_format((int) $payment->amount).'円',
            'purchase_date' => CarbonImmutable::parse($payment->succeeded_at)->setTimezone('Asia/Tokyo')->format('Y年n月j日 H:i:s').'（日本時間）',
            'payment_method' => V2PaymentMethodPolicy::methodLabel($payment->payment_method),
            'acquired_coins' => number_format($paid + $product + $limited + $rankAmount).' コイン',
            'purchase_receipt' => $lines,
        ];
        DB::table('payment_receipt_snapshots')->insert([
            'payment_id' => $paymentId, 'snapshot' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
