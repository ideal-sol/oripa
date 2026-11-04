<?php

namespace App\Domain\MemberRank\Services;

use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankEventPromotionService
{
    public function apply(int $userId, string $kind, array $version, CarbonImmutable $instant, ?int $transferHistoryId = null, ?CarbonImmutable $processedAt = null): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Cumulative promotion requires the owning transaction.');
        }
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
        $state = DB::table('member_rank_states')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
        if ($user->state !== 'active' || $state->frozen_at !== null) {
            throw new \LogicException('Cumulative promotion requires an active unfrozen member.');
        }
        $month = $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString();
        if ($kind === 'monthly' && DB::table('member_rank_cumulative_events')->where('user_id', $userId)
            ->where('kind', 'monthly')->where('month', $month)->exists()) {
            return (int) $state->level;
        }
        $points = app(V2MemberRankCumulativePoints::class)->total($userId);
        $compensation = 0;
        $balance = 0;
        if ($kind === 'monthly') {
            $previous = app(V2MemberRankSettingsService::class)->effectiveAt($instant->setTimezone('Asia/Tokyo')->startOfMonth()->subMonth());
            $oldLine = (int) $previous['ranks'][(int) $state->level - 1]['reach_line'];
            $newLine = (int) $version['ranks'][(int) $state->level - 1]['reach_line'];
            $balance = app(V2MemberRankCumulativePoints::class)->components($userId)['compensation'];
            $compensation = $this->compensationDelta($points, $balance, $oldLine, $newLine);
            $points = app(V2MemberRankCumulativePoints::class)->sum([
                ...app(V2MemberRankCumulativePoints::class)->components($userId), $compensation,
            ]);
        }
        $reached = app(V2MemberRankCorrectionService::class)->reachedLevel($points, $version);
        $level = max((int) $state->level, $reached);
        $publicId = (string) Str::uuid7();
        // The logical month selects settings; issuance and expiry use actual processing time.
        $processedAt ??= $instant;
        $timestamp = V2DatabaseTimestamp::format($processedAt);
        $eventId = DB::table('member_rank_cumulative_events')->insertGetId([
            'public_id' => $publicId, 'user_id' => $userId, 'kind' => $kind, 'month' => $month,
            'setting_revision' => $version['revision'], 'transfer_history_id' => $transferHistoryId,
            'rank_points' => $points, 'from_level' => $state->level, 'to_level' => $level, 'occurred_at' => $timestamp,
        ]);
        if ($compensation !== 0) {
            DB::table('member_rank_compensation_entries')->insert([
                'user_id' => $userId, 'event_id' => $eventId, 'delta' => $compensation,
                'remaining' => $balance + $compensation, 'occurred_at' => $timestamp,
            ]);
        }
        if ($level > (int) $state->level) {
            DB::table('member_rank_changes')->insert([
                'user_id' => $userId, 'from_level' => $state->level, 'to_level' => $level, 'reason' => 'promotion',
                'month' => $month, 'rank_points' => $points, 'setting_revision' => $version['revision'],
                'cumulative_event_id' => $eventId, 'occurred_at' => $timestamp,
            ]);
            DB::table('member_rank_states')->where('user_id', $userId)->update([
                'level' => $level, 'revision' => DB::raw('revision + 1'), 'updated_at' => $timestamp,
            ]);
        }
        app(V2MemberRankAccountingService::class)->consumeReachedEntitlements(
            $userId, $reached, $month, $points, $version, $kind, $publicId, null, $processedAt, $eventId
        );
        if ($compensation !== 0) {
            app(\App\Domain\Audit\V2\Services\V2AuditLogService::class)->record('member_rank.compensation.recorded', [
                'target_type' => 'user', 'target_public_id' => $user->public_id,
                'before' => ['compensation' => $balance], 'after' => ['compensation' => $balance + $compensation],
                'metadata' => ['event_id' => $publicId, 'month' => $month, 'setting_revision' => $version['revision'], 'delta' => $compensation],
            ]);
        }

        return $level;
    }

    public function compensationDelta(int $points, int $balance, int $oldLine, int $newLine): int
    {
        if ($newLine > $oldLine) {
            // Preserve any shortage that already existed under the old line.
            return max(0, $newLine - $points) - max(0, $oldLine - $points);
        }
        if ($newLine < $oldLine) {
            return -min($balance, $oldLine - $newLine, max(0, $points - $newLine));
        }

        return 0;
    }
}
