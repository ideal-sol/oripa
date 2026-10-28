<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Database\Query\Builder;

final class V2PaymentCommercialQuery
{
    public static function apply(Builder $query, string $paymentId): void
    {
        // Match v2_payment_commercial without invoking a SQL function for every row.
        // Require explicit commercial authority; absence is never commercial.
        $query->whereExists(function (Builder $excluded) use ($paymentId): void {
            $excluded->selectRaw('1')->from('payment_commercial_classifications as commercial_exclusion')
                ->whereColumn('commercial_exclusion.payment_id', $paymentId)
                ->where('commercial_exclusion.classification', 'commercial')
                ->where('commercial_exclusion.reason', 'provider_live_mode');
        })->whereNotExists(function (Builder $excluded) use ($paymentId): void {
            $excluded->selectRaw('1')->from('member_rank_prelaunch_excluded_origins as prelaunch_exclusion')
                ->whereColumn('prelaunch_exclusion.payment_id', $paymentId);
        });
    }
}
