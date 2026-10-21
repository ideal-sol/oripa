<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\Reporting\Services\V2ReportingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2MemberRankAdminReadService
{
    public function __construct(
        private readonly V2MemberRankResolver $resolver,
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2MemberRankMonthlyWorker $worker,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    public function detail(int $userId): array
    {
        $now = CarbonImmutable::now();
        $projection = $this->resolver->project($userId, $now);
        $state = DB::table('member_rank_states')->where('user_id', $userId)->first();
        $user = DB::table('users')->where('id', $userId)->firstOrFail();
        $frozenAt = $state?->frozen_at ?? (in_array($user->state, ['closed', 'anonymized'], true) ? $user->updated_at : null);
        $month = ($frozenAt === null ? $now : CarbonImmutable::parse($frozenAt))
            ->setTimezone('Asia/Tokyo')->startOfMonth();
        $version = $this->settings->effectiveAt($month);
        $level = $projection['level'];
        $rankPoints = $projection['rank_points'];
        $next = $level < 7 ? $version['ranks'][$level] : null;
        $maintain = $level > 1 ? $version['ranks'][$level - 1] : null;
        $previousMonth = $month->subMonth();
        $previousPoints = (int) DB::table('member_rank_month_totals')->where('user_id', $userId)
            ->where('month', $previousMonth->toDateString())->value('points');
        if ($maintain !== null) {
            $maintain = $this->settings->effectiveAt($previousMonth)['ranks'][$level - 1];
        }
        $achievements = DB::table('member_rank_achievements as achievement')
            ->leftJoin('member_rank_entitlements as slot', 'slot.achievement_id', '=', 'achievement.id')
            ->leftJoin('member_rank_bonus_recoveries as recovery', 'recovery.achievement_id', '=', 'achievement.id')
            ->where('achievement.user_id', $userId)->orderByDesc('achievement.id')
            ->get(['achievement.*', 'slot.achievement_id as consumed_id', 'recovery.recovered_amount', 'recovery.shortfall_amount'])
            ->map(fn (object $row): array => [
                'id' => $row->public_id, 'rank' => $this->code((int) $row->level), 'generation' => (int) $row->generation,
                'month' => $row->month, 'setting_revision' => (int) $row->setting_revision,
                'bonus_amount' => (int) $row->bonus_amount, 'point_type' => 'free',
                'basis' => $row->basis, 'origin_id' => $row->origin_public_id, 'occurred_at' => $this->timestamp($row->occurred_at),
                'consumed' => $row->consumed_id !== null,
                'recovered_amount' => $row->recovered_amount === null ? null : (int) $row->recovered_amount,
                'shortfall_amount' => $row->shortfall_amount === null ? null : (int) $row->shortfall_amount,
            ])->all();

        return [
            'enabled' => $projection['enabled'], 'rank' => $this->code($level), 'month' => $month->toDateString(),
            'rank_points' => $rankPoints, 'revision' => (int) ($state?->revision ?? 0),
            'default_skip_count' => $version['default_skip_count'], 'skip_count' => $projection['skip_count'],
            'guaranteed_until' => $projection['skip_count'] === 0 ? null
                : $month->addMonths($projection['skip_count'] + 1)->subSecond()->utc()->toIso8601String(),
            'transferred_at' => $this->timestamp($state?->transferred_at),
            'frozen_at' => $this->timestamp($frozenAt), 'last_transfer' => $this->lastTransfer($userId),
            'next_rank' => $next === null ? null : ['rank' => $next['code'], 'month' => $month->toDateString(),
                'line' => $next['reach_line'], 'remaining' => max(0, $next['reach_line'] - $rankPoints)],
            'maintain' => $maintain === null ? null : ['rank' => $maintain['code'], 'month' => $previousMonth->toDateString(),
                'line' => $maintain['maintain_line'], 'remaining' => max(0, $maintain['maintain_line'] - $previousPoints)],
            'achievements' => $achievements,
        ];
    }

    public function lastTransfer(int $userId): ?array
    {
        $row = $this->rankHistoryQuery()->where('change.user_id', $userId)->where('manual.kind', 'transfer')
            ->orderByDesc('change.id')->first();

        return $row === null ? null : $this->historyRow($row);
    }

    public function summary(): array
    {
        $now = CarbonImmutable::now();
        $month = $now->setTimezone('Asia/Tokyo')->startOfMonth();
        $ids = DB::table('users')->where('state', 'active')->orderBy('id')->pluck('id')->all();
        $projections = $this->resolver->projectMany($ids, $now);
        $counts = array_fill(1, 7, 0);
        foreach ($projections as $projection) {
            $counts[$projection['level']]++;
        }
        $progress = $this->worker->progress($now);
        $bonus = function (string $source) use ($month): array {
            $row = DB::table('point_operations as operation')
                ->join('point_ledger_entries as entry', 'entry.point_operation_id', '=', 'operation.id')
                ->where('operation.source_type', $source)->where('entry.entry_type', 'grant')
                ->where('operation.occurred_at', '>=', $month->utc()->toIso8601String())
                ->where('operation.occurred_at', '<', $month->addMonth()->utc()->toIso8601String())
                ->selectRaw('count(DISTINCT operation.id) as count, COALESCE(sum(entry.amount_delta), 0) as amount')->first();

            return ['count' => (int) $row->count, 'amount' => (int) $row->amount, 'point_type' => 'free'];
        };
        $reviews = DB::table('member_rank_bonus_recoveries as recovery')
            ->join('users as user', 'user.id', '=', 'recovery.user_id')
            ->join('member_rank_achievements as achievement', 'achievement.id', '=', 'recovery.achievement_id')
            ->where('recovery.shortfall_amount', '>', 0)->orderByDesc('recovery.id')
            ->get(['recovery.*', 'user.public_id as user_public_id', 'achievement.public_id as achievement_public_id', 'achievement.level'])
            ->map(fn (object $row): array => [
                'id' => $row->public_id, 'user_id' => $row->user_public_id, 'achievement_id' => $row->achievement_public_id,
                'rank' => $this->code((int) $row->level), 'recovered_amount' => (int) $row->recovered_amount,
                'shortfall_amount' => (int) $row->shortfall_amount, 'occurred_at' => $this->timestamp($row->occurred_at),
            ])->all();

        return [
            'enabled' => $progress['enabled'], 'month' => $month->toDateString(), 'active_total' => count($ids),
            'ranks' => array_map(fn (int $level): array => ['rank' => $this->code($level), 'count' => $counts[$level]], range(1, 7)),
            'promotion_bonus' => $bonus('member_rank_promotion'), 'purchase_bonus' => $bonus('member_rank_purchase'),
            'progress' => [
                'provisional' => $progress['provisional'], 'remaining' => $progress['remaining'],
                'last_completed_month' => $progress['last_completed_month'], 'status' => $progress['job']?->status,
                'lease_expired' => $progress['job']?->status === 'processing'
                    && CarbonImmutable::parse($progress['job']->lease_expires_at)->lessThanOrEqualTo($now),
            ], 'reviews' => $reviews,
            'payment_adjustment_reviews' => DB::table('member_rank_payment_adjustments as review')
                ->join('users as user', 'user.id', '=', 'review.user_id')
                ->join('payments as payment', 'payment.id', '=', 'review.payment_id')
                ->join('payment_adjustments as adjustment', 'adjustment.id', '=', 'review.adjustment_id')
                ->where(fn ($query) => $query->where('review.effect', 'past_month')->orWhere('review.purchase_bonus_shortfall', '>', 0))
                ->orderByDesc('review.id')
                ->get(['review.*', 'user.public_id as user_public_id', 'payment.public_id as payment_public_id',
                    'adjustment.public_id as adjustment_public_id', 'adjustment.type'])
                ->map(fn (object $row): array => [
                    'id' => $row->public_id, 'user_id' => $row->user_public_id, 'payment_id' => $row->payment_public_id,
                    'adjustment_id' => $row->adjustment_public_id, 'type' => $row->type, 'month' => $row->month,
                    'past_month' => $row->effect === 'past_month', 'requested_points' => (int) $row->requested_points,
                    'applied_points' => (int) $row->applied_points, 'purchase_bonus_shortfall' => (int) $row->purchase_bonus_shortfall,
                    'occurred_at' => $this->timestamp($row->occurred_at),
                ])->all(),
        ];
    }

    public function history(?int $userId, array $filters, bool $points = false, bool $manualOnly = false): array
    {
        $allowed = $manualOnly ? ['cursor', 'limit', 'kind', 'date_from', 'date_to'] : ['cursor', 'limit', 'history'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw $this->invalid();
        }
        $limit = $filters['limit'] ?? '20';
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            throw $this->invalid();
        }
        $cursor = $filters['cursor'] ?? null;
        if ($cursor !== null && ! is_string($cursor)) {
            throw $this->invalid();
        }
        $query = $points ? $this->pointHistoryQuery() : $this->rankHistoryQuery();
        if ($userId !== null) {
            $query->where('change.user_id', $userId);
        }
        if ($manualOnly) {
            $query->where('change.reason', 'manual');
        }
        if (isset($filters['kind'])) {
            if (! in_array($filters['kind'], V2MemberRankAdminService::KINDS, true)) {
                throw $this->invalid();
            }
            $query->where('manual.kind', $filters['kind']);
        }
        $dates = [];
        foreach (['date_from', 'date_to'] as $key) {
            if (! isset($filters[$key])) {
                continue;
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters[$key], 'Asia/Tokyo');
            } catch (\Throwable) {
                throw $this->invalid();
            }
            if ($date->toDateString() !== $filters[$key]) {
                throw $this->invalid();
            }
            $dates[$key] = $date;
            $query->where($points ? 'change.recorded_at' : 'change.occurred_at', $key === 'date_from' ? '>=' : '<',
                ($key === 'date_from' ? $date : $date->addDay())->utc()->toIso8601String());
        }
        if (isset($dates['date_from'], $dates['date_to']) && $dates['date_to']->lessThan($dates['date_from'])) {
            throw $this->invalid();
        }
        $before = $this->cursor->decode($cursor);
        if ($before !== null) {
            $query->where('change.id', '<', $before);
        }
        $rows = $query->orderByDesc('change.id')->limit((int) $limit + 1)->get();
        $page = $rows->take((int) $limit);

        return ['items' => $page->map(fn (object $row): array => $this->historyRow($row, $points))->all(),
            'next_cursor' => $rows->count() > (int) $limit ? $this->cursor->encode((int) $page->last()->id) : null];
    }

    public function user(string $publicId): object
    {
        $user = DB::table('users')->where('public_id', $publicId)->first();
        if ($user === null) {
            throw new V2MemberRankException('ADMIN_USER_NOT_FOUND', 404, '会員が見つかりません。');
        }

        return $user;
    }

    private function rankHistoryQuery(): Builder
    {
        return DB::table('member_rank_changes as change')->join('users as user', 'user.id', '=', 'change.user_id')
            ->leftJoin('member_rank_manual_changes as manual', 'manual.id', '=', 'change.manual_change_id')
            ->leftJoin('member_rank_point_entries as origin', 'origin.id', '=', 'change.point_entry_id')
            ->leftJoin('admins as admin', function ($join): void {
                $join->whereRaw('admin.id = COALESCE(manual.admin_id, origin.admin_id)');
            })
            ->select(['change.*', 'user.public_id as user_public_id', 'admin.public_id as actor_public_id',
                'manual.kind', DB::raw('COALESCE(manual.reason_text, origin.reason_text) as reason_text'), 'manual.grant_bonus', 'manual.bonus_amount', 'manual.skip_count',
                DB::raw('COALESCE(manual.public_id, origin.public_id) as origin_public_id')]);
    }

    private function pointHistoryQuery(): Builder
    {
        return DB::table('member_rank_point_entries as change')->join('users as user', 'user.id', '=', 'change.user_id')
            ->leftJoin('admins as admin', 'admin.id', '=', 'change.admin_id')
            ->leftJoin('payments as payment', fn ($join) => $join->whereRaw('payment.id = COALESCE(change.payment_id, change.origin_payment_id)'))
            ->leftJoin('payment_adjustments as adjustment', 'adjustment.id', '=', 'change.adjustment_id')
            ->select(['change.*', 'user.public_id as user_public_id', 'admin.public_id as actor_public_id',
                'payment.public_id as payment_public_id', 'adjustment.public_id as adjustment_public_id']);
    }

    private function historyRow(object $row, bool $points = false): array
    {
        return [
            'id' => $row->public_id, 'user_id' => $row->user_public_id, 'actor_id' => $row->actor_public_id,
            'reason' => $row->reason, 'reason_text' => $row->reason_text, 'kind' => $row->kind ?? null,
            'from_rank' => $points ? null : $this->code((int) $row->from_level),
            'to_rank' => $points ? null : $this->code((int) $row->to_level), 'month' => $row->month,
            'rank_points' => $points ? null : (int) $row->rank_points,
            'requested_delta' => $points ? (int) $row->requested_delta : null,
            'applied_delta' => $points ? (int) $row->applied_delta : null,
            'grant_bonus' => isset($row->grant_bonus) ? (bool) $row->grant_bonus : null,
            'bonus_amount' => isset($row->bonus_amount) ? (int) $row->bonus_amount : null,
            'skip_count' => isset($row->skip_count) ? (int) $row->skip_count : null,
            'setting_revision' => $points ? null : (int) $row->setting_revision,
            'origin_id' => $points ? ($row->payment_public_id ?? $row->public_id) : $row->origin_public_id,
            ...($points && $row->reason === 'payment_adjustment'
                ? ['payment_id' => $row->payment_public_id, 'adjustment_id' => $row->adjustment_public_id] : []),
            'occurred_at' => $this->timestamp($points ? $row->recorded_at : $row->occurred_at),
        ];
    }

    private function code(int $level): string
    {
        return V2MemberRankSettingsService::CODES[$level - 1];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
    }

    private function invalid(): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_QUERY_INVALID', 422, '検索条件を確認してください。');
    }
}
