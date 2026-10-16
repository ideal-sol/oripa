<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Point\Services\V2PointService;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankAccountingService
{
    public function __construct(
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2PointService $points
    ) {
    }

    public function lockUserIfEnabled(int $userId): void
    {
        $this->requireTransaction();
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            return;
        }
        DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
        DB::table('member_rank_states')->where('user_id', $userId)->lockForUpdate()->first();
    }

    public function accountGrantedPayment(int $paymentId): void
    {
        $this->requireTransaction();
        $setting = DB::table('member_rank_settings')->where('id', 1)->firstOrFail();
        if (! $setting->enabled) {
            return;
        }
        $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->firstOrFail();
        $this->lockUserIfEnabled((int) $payment->user_id);
        if (DB::table('member_rank_point_entries')->where('payment_id', $paymentId)->exists()) {
            return;
        }
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->firstOrFail();
        if ($payment->status !== 'succeeded' || (int) $payment->amount !== (int) $payment->paid_point_amount) {
            throw new \LogicException('Member rank accounting requires a succeeded paid payment.');
        }
        $grantedAt = CarbonImmutable::parse($grant->granted_at);
        $monthStart = $grantedAt->setTimezone('Asia/Tokyo')->startOfMonth();
        $activationMonth = CarbonImmutable::parse($setting->activated_at)->setTimezone('Asia/Tokyo')->startOfMonth();
        if ($monthStart->lessThan($activationMonth)) {
            return;
        }
        $month = $monthStart->toDateString();
        $recordedAt = CarbonImmutable::now()->startOfSecond();
        $version = $this->settings->effectiveAt($monthStart, (int) $setting->revision);
        DB::table('member_rank_states')->insertOrIgnore([
            'user_id' => $payment->user_id, 'baseline_month' => $month,
            'created_at' => V2DatabaseTimestamp::format($recordedAt),
            'updated_at' => V2DatabaseTimestamp::format($recordedAt),
        ]);
        $state = DB::table('member_rank_states')->where('user_id', $payment->user_id)->lockForUpdate()->firstOrFail();
        DB::table('member_rank_month_totals')->insertOrIgnore(['user_id' => $payment->user_id, 'month' => $month]);
        $total = DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)->where('month', $month)
            ->lockForUpdate()->firstOrFail();
        $rankPoints = (int) $total->points + (int) $payment->amount;
        if (! is_int($rankPoints)) {
            throw new \OverflowException('Member rank points exceed the supported integer range.');
        }
        $entryId = DB::table('member_rank_point_entries')->insertGetId([
            'user_id' => $payment->user_id, 'month' => $month, 'payment_id' => $paymentId,
            'point_operation_id' => $grant->point_operation_id, 'reason' => 'payment',
            'requested_delta' => $payment->amount, 'applied_delta' => $payment->amount,
            'granted_at' => V2DatabaseTimestamp::format($grantedAt),
            'recorded_at' => V2DatabaseTimestamp::format($recordedAt),
        ]);
        DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)->where('month', $month)
            ->update(['points' => $rankPoints]);
        $reachedLevel = 1;
        foreach ($version['ranks'] as $index => $rank) {
            if ($index > 0 && $rankPoints >= $rank['reach_line']) {
                $reachedLevel = $index + 1;
            }
        }
        if ($reachedLevel > (int) $state->level) {
            DB::table('member_rank_changes')->insert([
                'user_id' => $payment->user_id, 'from_level' => $state->level, 'to_level' => $reachedLevel,
                'reason' => 'promotion', 'month' => $month, 'rank_points' => $rankPoints,
                'setting_revision' => $version['revision'], 'point_entry_id' => $entryId,
                'occurred_at' => V2DatabaseTimestamp::format($recordedAt),
            ]);
            DB::table('member_rank_states')->where('user_id', $payment->user_id)->update([
                'level' => $reachedLevel, 'revision' => (int) $state->revision + 1,
                'updated_at' => V2DatabaseTimestamp::format($recordedAt),
            ]);
        }
        $this->consumeReachedEntitlements(
            (int) $payment->user_id, $reachedLevel, $month, $rankPoints, $version,
            'rank_points', $payment->public_id, $entryId, $recordedAt
        );
    }

    private function consumeReachedEntitlements(
        int $userId,
        int $reachedLevel,
        string $month,
        int $rankPoints,
        array $version,
        string $basis,
        string $originPublicId,
        int $entryId,
        CarbonImmutable $occurredAt
    ): void {
        for ($level = 2; $level <= $reachedLevel; $level++) {
            DB::table('member_rank_entitlements')->insertOrIgnore(['user_id' => $userId, 'level' => $level]);
            $slot = DB::table('member_rank_entitlements')->where('user_id', $userId)->where('level', $level)
                ->lockForUpdate()->firstOrFail();
            if ($slot->achievement_id !== null) {
                continue;
            }
            $generation = (int) DB::table('member_rank_achievements')->where('user_id', $userId)
                ->where('level', $level)->max('generation') + 1;
            $publicId = (string) Str::uuid7();
            $amount = $version['ranks'][$level - 1]['promotion_bonus'];
            $achievementId = DB::table('member_rank_achievements')->insertGetId([
                'public_id' => $publicId, 'user_id' => $userId, 'level' => $level,
                'generation' => $generation, 'month' => $month, 'rank_points' => $rankPoints,
                'setting_revision' => $version['revision'], 'basis' => $basis, 'origin_public_id' => $originPublicId,
                'point_entry_id' => $entryId, 'bonus_amount' => $amount,
                'occurred_at' => V2DatabaseTimestamp::format($occurredAt),
            ]);
            $this->points->grantMemberRankPromotion($userId, $achievementId, $publicId, $amount, $occurredAt);
            DB::table('member_rank_entitlements')->where('user_id', $userId)->where('level', $level)
                ->update(['achievement_id' => $achievementId]);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Member rank accounting requires the owning transaction.');
        }
    }
}
