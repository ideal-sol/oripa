<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use Illuminate\Support\Facades\DB;

final class V2PaymentAdjustmentLots
{
    public function inspect(object $payment, bool $lock = false): array
    {
        $grant = DB::table('payment_point_grants')->where('payment_id', $payment->id)->first();
        $bonus = DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->first();
        if ($grant === null) {
            throw new V2PaymentException('REFUND_POINT_GRANT_NOT_FOUND');
        }
        $paid = (int) $payment->paid_point_amount;
        $paymentFree = self::add((int) $payment->free_point_amount, (int) ($payment->limited_bonus_point_amount ?? 0));
        $purchaseBonus = (int) ($bonus?->bonus_amount ?? 0);
        $free = self::add($paymentFree, $purchaseBonus);
        $operations = array_values(array_filter([$grant->point_operation_id, $bonus?->point_operation_id]));
        $query = DB::table('point_lots')->whereIn('grant_operation_id', $operations)->orderBy('id');
        $lots = ($lock ? $query->lockForUpdate() : $query)->get();
        $paymentLots = $lots->where('grant_operation_id', $grant->point_operation_id);
        $bonusLots = $bonus?->point_operation_id === null ? collect() : $lots->where('grant_operation_id', $bonus->point_operation_id);
        $valid = $paid > 0 && $paid === (int) $payment->amount
            && $paymentLots->count() === 1 + ($paymentFree > 0 ? 1 : 0)
            && $paymentLots->where('point_type', 'paid')->count() === 1
            && (int) $paymentLots->where('point_type', 'paid')->sum('granted_amount') === $paid
            && (int) $paymentLots->where('point_type', 'free')->sum('granted_amount') === $paymentFree
            && $bonusLots->count() === ($purchaseBonus > 0 ? 1 : 0)
            && (int) $bonusLots->where('point_type', 'free')->sum('granted_amount') === $purchaseBonus
            && $lots->every(fn (object $lot): bool => (int) $lot->user_id === (int) $payment->user_id)
            && ($bonus === null || ((int) $bonus->user_id === (int) $payment->user_id
                && (($purchaseBonus === 0) === ($bonus->point_operation_id === null))));
        $operation = DB::table('point_operations')->where('id', $grant->point_operation_id)->first();
        $valid = $valid && $operation !== null && $operation->source_type === 'payment'
            && $operation->operation_type === 'payment_grant'
            && (int) $operation->source_id === (int) $payment->id
            && (int) $operation->user_id === (int) $payment->user_id;
        if ($purchaseBonus > 0) {
            $bonusOperation = DB::table('point_operations')->where('id', $bonus->point_operation_id)->first();
            $valid = $valid && $bonusOperation !== null && $bonusOperation->source_type === 'member_rank_purchase'
                && $bonusOperation->operation_type === 'free_grant'
                && (int) $bonusOperation->source_id === (int) $payment->id
                && (int) $bonusOperation->user_id === (int) $payment->user_id
                && DB::table('point_ledger_entries')->where('point_operation_id', $bonus->point_operation_id)
                    ->where('entry_type', 'grant')->where('point_type', 'free')->where('amount_delta', $purchaseBonus)->count() === 1;
        }
        if (! $valid) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }

        return ['lots' => $lots, 'paid' => $paid, 'free' => $free, 'payment_free' => $paymentFree,
            'purchase_bonus' => $purchaseBonus, 'grant' => $grant];
    }

    public static function add(int $first, int $second): int
    {
        if ($first < 0 || $second < 0 || $first > PHP_INT_MAX - $second) {
            throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }

        return $first + $second;
    }

    public function bonusImpact(array $source, int $reversedFree): array
    {
        $reversed = min($source['purchase_bonus'], max(0, $reversedFree - $source['payment_free']));

        return ['required_amount' => $source['purchase_bonus'], 'reversed_amount' => $reversed,
            'shortfall_amount' => $source['purchase_bonus'] - $reversed];
    }
}
