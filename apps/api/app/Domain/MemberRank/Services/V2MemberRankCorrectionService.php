<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Point\Services\V2PointService;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankCorrectionService
{
    public function __construct(
        private readonly V2MemberRankAccountingService $accounting,
        private readonly V2PointService $points
    ) {
    }

    public function reachedLevel(int $rankPoints, array $version): int
    {
        $level = 1;
        foreach ($version['ranks'] as $index => $rank) {
            if ($index > 0 && $rankPoints >= $rank['reach_line']) {
                $level = $index + 1;
            }
        }

        return $level;
    }

    public function recoverable(int $userId, string $month, int $rankPoints): array
    {
        return DB::table('member_rank_entitlements as slot')
            ->join('member_rank_achievements as achievement', 'achievement.id', '=', 'slot.achievement_id')
            ->join('member_rank_setting_values as value', function ($join): void {
                $join->on('value.version_revision', '=', 'achievement.setting_revision')->on('value.level', '=', 'achievement.level');
            })
            ->where('slot.user_id', $userId)->where('achievement.month', $month)
            ->where('achievement.basis', '<>', 'manual_rank')->where('value.reach_line', '>', $rankPoints)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('member_rank_bonus_recoveries as recovery')
                ->whereColumn('recovery.achievement_id', 'achievement.id'))
            ->orderBy('achievement.level')->get(['achievement.*'])->all();
    }

    public function spendableFree(int $userId, CarbonImmutable $instant): int
    {
        $wallet = DB::table('wallets')->where('user_id', $userId)->first();
        if ($wallet === null) {
            return 0;
        }
        $lots = (int) DB::table('point_lots')->where('user_id', $userId)->where('point_type', 'free')
            ->where('expire_at', '>', V2DatabaseTimestamp::format($instant))
            ->sum(DB::raw('remaining_amount - reserved_amount'));

        return min($lots, (int) $wallet->free_balance - (int) $wallet->free_reserved_balance);
    }

    public function applyIncrease(int $userId, int $entryId, array $version, CarbonImmutable $instant): void
    {
        $entry = $this->entry($userId, $entryId);
        if ((int) $entry->applied_delta <= 0) {
            throw new \LogicException('Member rank increase requires a positive entry.');
        }
        $state = DB::table('member_rank_states')->where('user_id', $userId)->firstOrFail();
        $total = (int) DB::table('member_rank_month_totals')->where('user_id', $userId)->where('month', $entry->month)->value('points');
        $reached = $this->reachedLevel($total, $version);
        if ($reached > (int) $state->level) {
            $this->change($userId, $entryId, $state, $reached, 'promotion', $total, $version, $instant);
        }
        $this->accounting->consumeReachedEntitlements($userId, $reached, $entry->month, $total, $version,
            'manual_rank_points', $entry->public_id, $entryId, $instant);
    }

    public function applyDecrease(int $userId, int $entryId, array $version, CarbonImmutable $instant): array
    {
        $entry = $this->entry($userId, $entryId);
        if ((int) $entry->applied_delta >= 0 || $entry->month !== $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString()) {
            throw new \LogicException('Member rank correction requires a current-month negative entry.');
        }
        $state = DB::table('member_rank_states')->where('user_id', $userId)->firstOrFail();
        $total = (int) DB::table('member_rank_month_totals')->where('user_id', $userId)->where('month', $entry->month)->value('points');
        $level = min((int) $state->level, max((int) $state->baseline_level, $this->reachedLevel($total, $version)));
        if ($level < (int) $state->level) {
            $this->change($userId, $entryId, $state, $level, 'promotion_reversal', $total, $version, $instant);
        }
        $result = [];
        foreach ($this->recoverable($userId, $entry->month, $total) as $achievement) {
            $recovery = $this->points->recoverMemberRankPromotion($userId, $achievement->public_id, (int) $achievement->bonus_amount, $instant);
            $shortfall = (int) $achievement->bonus_amount - $recovery['recovered_amount'];
            DB::table('member_rank_bonus_recoveries')->insert([
                'public_id' => (string) Str::uuid7(), 'user_id' => $userId, 'achievement_id' => $achievement->id,
                'point_entry_id' => $entryId, 'point_operation_id' => $recovery['operation_id'],
                'requested_amount' => $achievement->bonus_amount, 'recovered_amount' => $recovery['recovered_amount'],
                'shortfall_amount' => $shortfall, 'occurred_at' => V2DatabaseTimestamp::format($instant),
            ]);
            if ($shortfall === 0) {
                DB::table('member_rank_entitlements')->where('user_id', $userId)->where('level', $achievement->level)
                    ->where('achievement_id', $achievement->id)->update(['achievement_id' => null]);
            }
            $result[] = ['achievement_id' => $achievement->public_id, 'recovered_amount' => $recovery['recovered_amount'], 'shortfall_amount' => $shortfall];
        }

        return $result;
    }

    private function entry(int $userId, int $entryId): object
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Member rank correction requires the owning transaction.');
        }
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            throw new \LogicException('Member rank correction requires the enabled feature.');
        }
        DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();

        return DB::table('member_rank_point_entries')->where('id', $entryId)->where('user_id', $userId)->firstOrFail();
    }

    private function change(int $userId, int $entryId, object $state, int $level, string $reason, int $total, array $version, CarbonImmutable $instant): void
    {
        DB::table('member_rank_changes')->insert([
            'user_id' => $userId, 'from_level' => $state->level, 'to_level' => $level, 'reason' => $reason,
            'month' => $state->baseline_month, 'rank_points' => $total, 'setting_revision' => $version['revision'],
            'point_entry_id' => $entryId, 'occurred_at' => V2DatabaseTimestamp::format($instant),
        ]);
        DB::table('member_rank_states')->where('user_id', $userId)->update(['level' => $level]);
    }
}
