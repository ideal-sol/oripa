<?php

namespace App\Domain\MemberRank\Services;

use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2MemberRankResolver
{
    public function __construct(
        private readonly V2MemberRankMonthlyEvaluator $evaluator
    ) {
    }

    public function project(int $userId, ?CarbonImmutable $instant = null): array
    {
        $snapshot = $this->snapshot($userId);
        $result = $this->evaluator->evaluate(
            $snapshot['enabled'] ? $snapshot['state'] : null,
            $snapshot['enabled'] ? $snapshot['totals'] : [],
            $snapshot['versions'], $snapshot['frozen_at'] === null
                ? ($instant ?? CarbonImmutable::now()) : CarbonImmutable::parse($snapshot['frozen_at'])
        );

        return ['enabled' => $snapshot['enabled'], ...$result];
    }

    public function projectMany(array $userIds, ?CarbonImmutable $instant = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $template = $this->snapshot((int) $userIds[0]);
        $rows = DB::table('users')->whereIn('users.id', $userIds)
            ->leftJoin('member_rank_states as member_state', 'member_state.user_id', '=', 'users.id')
            ->selectRaw("users.id, users.state as user_state, users.updated_at, to_jsonb(member_state) as rank_state,
                COALESCE((SELECT jsonb_object_agg(month::text, points) FROM member_rank_month_totals WHERE user_id = users.id), '{}'::jsonb) as totals")
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $state = $row->rank_state === null ? null : json_decode($row->rank_state, true, 512, JSON_THROW_ON_ERROR);
            $frozen = $state['frozen_at'] ?? (in_array($row->user_state, ['closed', 'anonymized'], true) ? $row->updated_at : null);
            $result[(int) $row->id] = ['enabled' => $template['enabled'], ...$this->evaluator->evaluate(
                $template['enabled'] ? $state : null,
                $template['enabled'] ? json_decode($row->totals, true, 512, JSON_THROW_ON_ERROR) : [],
                $template['versions'], $frozen === null ? ($instant ?? CarbonImmutable::now()) : CarbonImmutable::parse($frozen)
            )];
        }

        return $result;
    }

    public function freeze(int $userId, CarbonImmutable $instant): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Member rank closure requires the owning transaction.');
        }
        $projection = $this->materialize($userId, $instant);
        if (! $projection['enabled']) {
            return;
        }
        DB::table('member_rank_states')->insertOrIgnore([
            'user_id' => $userId, 'baseline_month' => $projection['baseline_month'],
            'created_at' => V2DatabaseTimestamp::format($instant), 'updated_at' => V2DatabaseTimestamp::format($instant),
        ]);
        DB::table('member_rank_states')->where('user_id', $userId)->whereNull('frozen_at')->update([
            'frozen_at' => V2DatabaseTimestamp::format($instant),
        ]);
    }

    public function materialize(int $userId, ?CarbonImmutable $instant = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Member rank materialization requires the owning transaction.');
        }
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            return $this->project($userId, $instant);
        }
        DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
        DB::table('member_rank_states')->where('user_id', $userId)->lockForUpdate()->first();
        $result = $this->project($userId, $instant ?? CarbonImmutable::now());
        if (DB::table('member_rank_states')->where('user_id', $userId)->whereNotNull('frozen_at')->exists()
            || DB::table('users')->where('id', $userId)->whereIn('state', ['closed', 'anonymized'])->exists()) {
            return $result;
        }
        if ($result['judgments'] === []) {
            return $result;
        }
        $recordedAt = V2DatabaseTimestamp::format(CarbonImmutable::now());
        foreach ($result['judgments'] as $judgment) {
            if ($judgment['from_level'] !== $judgment['to_level']) {
                DB::table('member_rank_changes')->insert([
                    'user_id' => $userId, 'reason' => 'demotion', 'point_entry_id' => null,
                    ...array_diff_key($judgment, ['skipped' => true]), 'occurred_at' => $recordedAt,
                ]);
            }
        }
        DB::table('member_rank_states')->where('user_id', $userId)->update([
            ...array_intersect_key($result, array_flip([
                'level', 'skip_count', 'baseline_month', 'baseline_level', 'last_judged_month',
            ])),
            'revision' => DB::raw('revision + 1'), 'updated_at' => $recordedAt,
        ]);

        return $result;
    }

    private function snapshot(int $userId): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT settings.enabled,
                COALESCE((SELECT frozen_at FROM member_rank_states WHERE user_id = ?),
                    (SELECT updated_at FROM users WHERE id = ? AND state IN ('closed', 'anonymized'))) AS frozen_at,
                (SELECT to_jsonb(state) FROM member_rank_states state WHERE state.user_id = ?) AS state,
                COALESCE((SELECT jsonb_object_agg(month::text, points) FROM member_rank_month_totals WHERE user_id = ?), '{}'::jsonb) AS totals,
                COALESCE((SELECT jsonb_agg(jsonb_build_object(
                    'revision', version.revision, 'effective_at', version.effective_at,
                    'maintain_lines', (SELECT jsonb_object_agg(level::text, maintain_line)
                        FROM member_rank_setting_values WHERE version_revision = version.revision)
                    ) ORDER BY version.effective_at DESC, version.revision DESC)
                    FROM member_rank_setting_versions version WHERE version.revision <= settings.revision), '[]'::jsonb) AS versions
            FROM member_rank_settings settings WHERE settings.id = 1
            SQL, [$userId, $userId, $userId, $userId]);
        if ($row === null) {
            throw new \LogicException('Member rank settings are unavailable.');
        }

        return [
            'enabled' => (bool) $row->enabled,
            'frozen_at' => $row->frozen_at,
            'state' => $row->state === null ? null : json_decode($row->state, true, 512, JSON_THROW_ON_ERROR),
            'totals' => json_decode($row->totals, true, 512, JSON_THROW_ON_ERROR),
            'versions' => json_decode($row->versions, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
