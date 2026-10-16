<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankSettingsService
{
    public const CODES = ['beginner', 'bronze', 'silver', 'gold', 'platinum', 'diamond', 'vip'];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadMemberRankSettings);

        return DB::transaction(function (): array {
            $state = DB::table('member_rank_settings')->where('id', 1)->sharedLock()->first();

            return $this->snapshot($state, CarbonImmutable::now());
        });
    }

    public function history(V2AdminAuthorizationContext $context, ?string $beforeRevision): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadMemberRankSettings);
        if ($beforeRevision !== null && (! ctype_digit($beforeRevision) || (int) $beforeRevision < 1)) {
            throw $this->invalid();
        }
        $query = DB::table('member_rank_setting_versions')->orderByDesc('revision');
        if ($beforeRevision !== null) {
            $query->where('revision', '<', (int) $beforeRevision);
        }
        $versions = $query->limit(51)->get();
        $page = $versions->take(50);

        return [
            'items' => $page->map(fn (object $version): array => [
                'before' => $version->before_revision === null ? null : $this->version((int) $version->before_revision),
                'after' => $this->version((int) $version->revision),
            ])->all(),
            'next_before_revision' => $versions->count() > 50 ? (int) $page->last()->revision : null,
        ];
    }

    public function update(V2AdminAuthorizationContext $context, string $key, array $input, bool $cancel = false): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageMemberRankSettings);
        $this->validate($input, $cancel);
        if (! preg_match('/\A[\x21-\x7e]{16,128}\z/', $key)) {
            throw $this->invalid();
        }
        try {
            return DB::transaction(function () use ($context, $admin, $key, $input, $cancel): array {
                $claim = $this->idempotency->claim(
                    $cancel ? 'member_rank.settings.cancel' : 'member_rank.settings.update',
                    'admin', $admin->public_id, $key, $input
                );
                if ($claim->replay) {
                    return ['data' => $claim->record->response_data['data'], 'idempotent_replay' => true];
                }
                $state = DB::table('member_rank_settings')->where('id', 1)->lockForUpdate()->first();
                $instant = CarbonImmutable::now()->startOfSecond();
                $before = $this->snapshot($state, $instant);
                if ($before['revision'] !== $input['expected_revision']) {
                    throw new V2MemberRankException('MEMBER_RANK_SETTINGS_REVISION_CONFLICT', 409, '最新の設定を取得してください。');
                }
                if (! $cancel && $state->enabled && ! $input['enabled']) {
                    throw new V2MemberRankException('MEMBER_RANK_DEACTIVATION_FORBIDDEN', 409, '運用開始後は無効化できません。');
                }
                if (! $cancel && ! $state->enabled && $input['enabled']) {
                    throw new V2MemberRankException('MEMBER_RANK_ACTIVATION_NOT_READY', 409, '会員ランク機能は準備中です。');
                }
                if ($cancel && $before['scheduled'] === null) {
                    throw new V2MemberRankException('MEMBER_RANK_SCHEDULE_NOT_FOUND', 409, '取消可能な翌月設定がありません。');
                }
                $values = $cancel ? $before['current'] : $input;
                $revision = $before['revision'] + 1;
                DB::table('member_rank_setting_versions')->insert([
                    'revision' => $revision, 'public_id' => (string) Str::uuid7(),
                    'action' => $cancel ? 'cancel' : 'schedule',
                    'effective_at' => $before['next_effective_at'],
                    'saved_at' => V2DatabaseTimestamp::format($instant), 'saved_by_admin_id' => $admin->id,
                    'before_revision' => ($before['scheduled'] ?? $before['current'])['revision'],
                    'default_skip_count' => $values['default_skip_count'],
                ]);
                foreach ($values['ranks'] as $index => $rank) {
                    DB::table('member_rank_setting_values')->insert([
                        'version_revision' => $revision, 'level' => $index + 1,
                        'reach_line' => $rank['reach_line'], 'maintain_line' => $rank['maintain_line'],
                        'promotion_bonus' => $rank['promotion_bonus'],
                        'purchase_bonus_rate_bp' => $rank['purchase_bonus_rate_bp'],
                    ]);
                }
                DB::table('member_rank_settings')->where('id', 1)->update(['revision' => $revision]);
                $state->revision = $revision;
                $after = $this->snapshot($state, $instant);
                $this->audit->record('member_rank.settings.updated', [
                    'request_id' => $context->requestId, 'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id, 'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin', 'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'member_rank_setting', 'target_public_id' => $state->public_id,
                    'before' => $this->auditSnapshot($before), 'after' => $this->auditSnapshot($after), 'outcome' => 'success',
                    'metadata' => ['action' => $cancel ? 'cancel' : 'schedule', 'effective_at' => $before['next_effective_at']],
                ]);
                $this->idempotency->complete($claim->record, 'member_rank_setting', $state->public_id, ['data' => $after]);

                return ['data' => $after, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2MemberRankException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED' ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409, '同じリクエストキーの処理を確認してください。'
            );
        }
    }

    private function auditSnapshot(array $snapshot): array
    {
        $result = array_intersect_key($snapshot, array_flip(['revision', 'enabled', 'activated_at', 'next_effective_at']));
        foreach (['current', 'scheduled'] as $kind) {
            $version = $snapshot[$kind];
            $result[$kind.'_revision'] = $version['revision'] ?? null;
            if ($version === null) {
                continue;
            }
            foreach (['action', 'effective_at', 'saved_at', 'saved_by', 'default_skip_count'] as $field) {
                $result[$kind.'_'.$field] = $version[$field];
            }
            foreach ($version['ranks'] as $rank) {
                foreach (['reach_line', 'maintain_line', 'promotion_bonus', 'purchase_bonus_rate_bp'] as $field) {
                    $result[$kind.'_'.$rank['code'].'_'.$field] = $rank[$field];
                }
            }
        }

        return $result;
    }

    public function effectiveAt(CarbonImmutable $instant, ?int $maximumRevision = null): array
    {
        $query = DB::table('member_rank_setting_versions')
            ->where('effective_at', '<=', V2DatabaseTimestamp::format($instant));
        if ($maximumRevision !== null) {
            $query->where('revision', '<=', $maximumRevision);
        }
        $revision = $query->orderByDesc('effective_at')->orderByDesc('revision')->value('revision');
        if ($revision === null) {
            throw new V2MemberRankException('MEMBER_RANK_SETTINGS_UNAVAILABLE', 503, '会員ランク設定を取得できません。');
        }

        return $this->version((int) $revision);
    }

    private function snapshot(?object $state, CarbonImmutable $instant): array
    {
        if ($state === null) {
            throw new V2MemberRankException('MEMBER_RANK_SETTINGS_UNAVAILABLE', 503, '会員ランク設定を取得できません。');
        }
        $next = $instant->setTimezone('Asia/Tokyo')->startOfMonth()->addMonth()->utc();
        $scheduled = DB::table('member_rank_setting_versions')
            ->where('effective_at', V2DatabaseTimestamp::format($next))
            ->where('revision', '<=', $state->revision)->orderByDesc('revision')->first();

        return [
            'revision' => (int) $state->revision, 'enabled' => (bool) $state->enabled,
            'activated_at' => $state->activated_at === null ? null : CarbonImmutable::parse($state->activated_at)->utc()->toIso8601String(),
            'current' => $this->effectiveAt($instant, (int) $state->revision),
            'scheduled' => $scheduled === null || $scheduled->action === 'cancel' ? null : $this->version((int) $scheduled->revision),
            'next_effective_at' => $next->toIso8601String(),
        ];
    }

    private function version(int $revision): array
    {
        $version = DB::table('member_rank_setting_versions')->where('revision', $revision)->first();
        $ranks = DB::table('member_rank_setting_values')->where('version_revision', $revision)->orderBy('level')->get();

        return [
            'revision' => $revision, 'action' => $version->action,
            'effective_at' => CarbonImmutable::parse($version->effective_at)->utc()->toIso8601String(),
            'saved_at' => CarbonImmutable::parse($version->saved_at)->utc()->toIso8601String(),
            'saved_by' => $version->saved_by_admin_id === null ? null : DB::table('admins')->where('id', $version->saved_by_admin_id)->value('public_id'),
            'default_skip_count' => (int) $version->default_skip_count,
            'ranks' => $ranks->map(fn (object $rank): array => [
                'code' => self::CODES[$rank->level - 1],
                'reach_line' => $rank->reach_line === null ? null : (int) $rank->reach_line,
                'maintain_line' => $rank->maintain_line === null ? null : (int) $rank->maintain_line,
                'promotion_bonus' => (int) $rank->promotion_bonus,
                'purchase_bonus_rate_bp' => (int) $rank->purchase_bonus_rate_bp,
            ])->all(),
        ];
    }

    private function validate(array $input, bool $cancel): void
    {
        $keys = $cancel ? ['expected_revision'] : ['expected_revision', 'enabled', 'default_skip_count', 'ranks'];
        if (count($input) !== count($keys) || array_diff($keys, array_keys($input)) !== []
            || ! is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1) {
            throw $this->invalid();
        }
        if ($cancel) {
            return;
        }
        if (! is_bool($input['enabled']) || ! is_int($input['default_skip_count'])
            || $input['default_skip_count'] < 0 || $input['default_skip_count'] > 12
            || ! is_array($input['ranks']) || ! array_is_list($input['ranks']) || count($input['ranks']) !== 7) {
            throw $this->invalid();
        }
        $previousReach = 0;
        $previousMaintain = 0;
        foreach ($input['ranks'] as $index => $rank) {
            $rankKeys = ['code', 'reach_line', 'maintain_line', 'promotion_bonus', 'purchase_bonus_rate_bp'];
            if (! is_array($rank) || count($rank) !== 5 || array_diff($rankKeys, array_keys($rank)) !== []
                || $rank['code'] !== self::CODES[$index]
                || ! is_int($rank['promotion_bonus']) || $rank['promotion_bonus'] < 0 || $rank['promotion_bonus'] > 1_000_000
                || ! is_int($rank['purchase_bonus_rate_bp']) || $rank['purchase_bonus_rate_bp'] < 0 || $rank['purchase_bonus_rate_bp'] > 10_000) {
                throw $this->invalid();
            }
            if ($index === 0) {
                if ($rank['reach_line'] !== null || $rank['maintain_line'] !== null
                    || $rank['promotion_bonus'] !== 0 || $rank['purchase_bonus_rate_bp'] !== 0) {
                    throw $this->invalid();
                }
                continue;
            }
            if (! is_int($rank['reach_line']) || ! is_int($rank['maintain_line'])
                || $rank['reach_line'] <= $previousReach || $rank['reach_line'] > 9_007_199_254_740_991
                || $rank['maintain_line'] < $previousMaintain || $rank['maintain_line'] > $rank['reach_line']) {
                throw $this->invalid();
            }
            $previousReach = $rank['reach_line'];
            $previousMaintain = $rank['maintain_line'];
        }
    }

    private function invalid(): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_SETTINGS_INVALID', 422, '会員ランク設定の入力値を確認してください。');
    }
}
