<?php

namespace App\Domain\Point\Services;

use Illuminate\Support\Facades\DB;

final class V2FinancialAdmission
{
    public function enter(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Financial admission requires the owning transaction.');
        }
        DB::select('SELECT v2_financial_admit()');
    }

    public function close(): void
    {
        if (DB::transactionLevel() !== 1) {
            throw new \LogicException('Financial publication requires a dedicated outer transaction.');
        }
        if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(740022, 1) AS acquired')->acquired) {
            throw new \App\Domain\MemberRank\Exceptions\V2MemberRankException(
                'MEMBER_RANK_ACTIVATION_BUSY', 409, '関連する処理の完了後に再試行してください。'
            );
        }
        DB::table('financial_admission_generations')->where('id', 1)->lock('FOR UPDATE NOWAIT')->sole();
    }
}
