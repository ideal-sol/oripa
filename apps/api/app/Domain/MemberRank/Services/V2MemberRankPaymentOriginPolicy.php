<?php

namespace App\Domain\MemberRank\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPaymentOriginPolicy
{
    public function eligible(int $paymentId): ?bool
    {
        $classification = DB::table('payment_commercial_classifications')->where('payment_id', $paymentId)->first();
        if ($classification === null) {
            if (DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
                throw new \LogicException('Rank payment requires an explicit commercial classification.');
            }
            return false;
        }
        $test = $classification->classification === 'test';
        if (($test && ! in_array($classification->reason, ['provider_test_mode', 'pay1_existing_fincode'], true))
            || (! $test && ($classification->classification !== 'commercial' || $classification->reason !== 'provider_live_mode'))
            || ($classification->reason === 'pay1_existing_fincode'
                && DB::table('payments')->where('id', $paymentId)->value('provider_code') !== 'fincode')) {
            throw new \LogicException('Invalid payment classification authority.');
        }
        $eligible = DB::selectOne('SELECT v2_member_rank_prelaunch_eligible(?) AS eligible', [$paymentId])->eligible;
        if ($eligible === null) {
            return $test ? false : null;
        }
        if ($eligible) {
            return ! $test;
        }
        $excluded = DB::table('member_rank_prelaunch_excluded_origins')->where('payment_id', $paymentId)->sole();
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->first();
        if ($grant === null && $excluded->grant_id === null
            && $excluded->point_operation_id === null && $excluded->granted_at === null) {
            return false;
        }
        if ($grant === null || $excluded->grant_id === null
            || (int) $grant->id !== (int) $excluded->grant_id
            || (int) $grant->point_operation_id !== (int) $excluded->point_operation_id
            || ! CarbonImmutable::parse($grant->granted_at)->equalTo(CarbonImmutable::parse($excluded->granted_at))) {
            throw new \LogicException('Excluded prelaunch payment origin does not match the immutable grant.');
        }

        return false;
    }
}
