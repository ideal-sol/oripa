<?php

namespace App\Domain\Payment\V2\Services;

use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2PaidyBuyerData
{
    public function forUser(User $user): array
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $query = DB::table('payments')->where('user_id', $user->id)->where('status', 'succeeded')->where('provider_code', '<>', 'paidy')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_adjustments')->whereColumn('payment_adjustments.payment_id', 'payments.id')
                ->whereIn('type', ['refund', 'chargeback'])->where('status', 'succeeded'));
        V2PaymentCommercialQuery::apply($query, 'payments.id');
        $all = (clone $query)->selectRaw('count(*) AS count, coalesce(sum(amount), 0) AS amount')->first();
        $recent = (clone $query)->where('succeeded_at', '>=', V2DatabaseTimestamp::format($now->subMonthsNoOverflow(3)))
            ->selectRaw('count(*) AS count, coalesce(sum(amount), 0) AS amount')->first();
        $last = (clone $query)->orderByDesc('succeeded_at')->orderByDesc('id')->first();
        $wallet = app(\App\Domain\Point\Services\V2CurrentUserPointReadService::class)->wallet($user);
        $data = [
            'user_id' => $user->public_id,
            'age' => max(0, (int) CarbonImmutable::parse($user->created_at)->setTimezone('Asia/Tokyo')->startOfDay()->diffInDays($now->startOfDay())),
            'ltv' => (int) $all->amount, 'order_count' => (int) $all->count,
            'order_count_last3months' => (int) $recent->count, 'order_amount_last3months' => (int) $recent->amount,
            'number_of_points' => $wallet['total_points'],
        ];
        if ($last !== null) {
            $data['last_order_amount'] = (int) $last->amount;
            $data['last_order_at'] = max(0, (int) CarbonImmutable::parse($last->succeeded_at)->setTimezone('Asia/Tokyo')->startOfDay()->diffInDays($now->startOfDay()));
        }
        return $data;
    }
}
