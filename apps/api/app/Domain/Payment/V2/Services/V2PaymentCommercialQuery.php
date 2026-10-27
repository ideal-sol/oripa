<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Database\Query\Builder;

final class V2PaymentCommercialQuery
{
    public static function apply(Builder $query, string $paymentId): void
    {
        // Match v2_payment_commercial without invoking a SQL function for every row.
        // Expose both anti-joins to PostgreSQL for bulk reports and exports.
        $query->whereNotExists(function (Builder $excluded) use ($paymentId): void {
            $excluded->selectRaw('1')->from('payment_commercial_classifications as commercial_exclusion')
                ->whereColumn('commercial_exclusion.payment_id', $paymentId)
                ->where('commercial_exclusion.classification', 'test');
        })->whereNotExists(function (Builder $excluded) use ($paymentId): void {
            $excluded->selectRaw('1')->from('member_rank_prelaunch_excluded_origins as prelaunch_exclusion')
                ->whereColumn('prelaunch_exclusion.payment_id', $paymentId);
        });
    }
}
