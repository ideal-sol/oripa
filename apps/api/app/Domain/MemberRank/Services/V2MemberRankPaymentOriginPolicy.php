<?php

namespace App\Domain\MemberRank\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPaymentOriginPolicy
{
    public function eligible(int $paymentId): ?bool
    {
        $test = DB::table('payment_commercial_classifications')->where('payment_id', $paymentId)->where('classification', 'test')->exists();
        $eligible = DB::selectOne('SELECT v2_member_rank_prelaunch_eligible(?) AS eligible', [$paymentId])->eligible;
        if ($eligible === null) {
            return $test ? false : null;
        }
        if ($eligible) {
            return ! $test;
        }
        $excluded = DB::table('member_rank_prelaunch_excluded_origins')->where('payment_id', $paymentId)->sole();
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->first();
        if ($grant === null || $excluded->grant_id === null
            || (int) $grant->id !== (int) $excluded->grant_id
            || (int) $grant->point_operation_id !== (int) $excluded->point_operation_id
            || ! CarbonImmutable::parse($grant->granted_at)->equalTo(CarbonImmutable::parse($excluded->granted_at))) {
            throw new \LogicException('Excluded prelaunch payment origin does not match the immutable grant.');
        }

        return false;
    }
}
