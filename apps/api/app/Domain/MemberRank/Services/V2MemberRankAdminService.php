<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankAdminService
{
    public const KINDS = ['transfer', 'support', 'payment_incident', 'other'];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2MemberRankResolver $resolver,
        private readonly V2MemberRankSettingsService $settings,
        private readonly V2MemberRankAccountingService $accounting,
        private readonly V2MemberRankCorrectionService $corrections,
        private readonly V2MemberRankAdminReadService $read,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function preview(V2AdminAuthorizationContext $context, string $publicId, string $operation, array $input): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ManageMemberRankUser);
        $request = $this->validate($operation, $input, false);
        $user = $this->read->user($publicId);
        $this->requireWritable($user);

        return $this->difference((int) $user->id, $operation, $request, CarbonImmutable::now()->startOfSecond());
    }

    public function write(V2AdminAuthorizationContext $context, string $publicId, string $operation, array $input, string $key): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageMemberRankUser);
        $request = $this->validate($operation, $input, true);
        if (! preg_match('/\A[\x21-\x7e]{16,128}\z/', $key)) {
            throw $this->invalid();
        }
        try {
            return $this->transactions->run(function () use ($context, $admin, $publicId, $operation, $request, $input, $key): array {
                $claim = $this->idempotency->claim('member_rank.user.'.$operation, 'admin', $admin->public_id, $key,
                    ['user_id' => $publicId, ...$input]);
                if ($claim->replay) {
                    return ['data' => $claim->record->response_data['data'], 'idempotent_replay' => true];
                }
                $user = DB::table('users')->where('public_id', $publicId)->lockForUpdate()->first();
                if ($user === null) {
                    throw new V2MemberRankException('ADMIN_USER_NOT_FOUND', 404, '会員が見つかりません。');
                }
                $this->requireWritable($user);
                $lockedState = DB::table('member_rank_states')->where('user_id', $user->id)->lockForUpdate()->first();
                if ((int) ($lockedState?->revision ?? 0) !== $input['expected_revision']) {
                    throw new V2MemberRankException('MEMBER_RANK_REVISION_CONFLICT', 409, '状態が変わりました。差分を再確認してください。');
                }
                $instant = CarbonImmutable::now()->startOfSecond();
                DB::table('member_rank_month_totals')->where('user_id', $user->id)
                    ->where('month', $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString())->lockForUpdate()->first();
                DB::table('member_rank_entitlements')->where('user_id', $user->id)->orderBy('level')->lockForUpdate()->get();
                $this->points->lockMemberRankWallet((int) $user->id);
                $lockedAt = CarbonImmutable::now()->startOfSecond();
                if ($lockedAt->setTimezone('Asia/Tokyo')->format('Y-m') !== $instant->setTimezone('Asia/Tokyo')->format('Y-m')) {
                    throw new V2MemberRankException('MEMBER_RANK_REVISION_CONFLICT', 409, '月が替わりました。差分を再確認してください。');
                }
                $instant = $lockedAt;
                $difference = $this->difference((int) $user->id, $operation, $request, $instant);
                if ($difference['expected_revision'] !== $input['expected_revision']
                    || ! hash_equals($difference['preview_token'], $input['preview_token'])) {
                    throw new V2MemberRankException('MEMBER_RANK_REVISION_CONFLICT', 409, '状態が変わりました。差分を再確認してください。');
                }
                if ($operation === 'rank' && $request['kind'] === 'transfer' && $difference['last_transfer'] !== null
                    && ($input['confirmed_transfer_id'] ?? null) !== $difference['last_transfer']['id']) {
                    throw new V2MemberRankException('MEMBER_RANK_TRANSFER_CONFIRMATION_REQUIRED', 409, '前回の引き継ぎ内容を確認してください。');
                }
                DB::table('member_rank_states')->insertOrIgnore([
                    'user_id' => $user->id, 'baseline_month' => $difference['month'],
                    'created_at' => V2DatabaseTimestamp::format($instant), 'updated_at' => V2DatabaseTimestamp::format($instant),
                ]);
                $this->resolver->materialize((int) $user->id, $instant);
                $state = DB::table('member_rank_states')->where('user_id', $user->id)->firstOrFail();
                $version = $this->settings->effectiveAt($instant->setTimezone('Asia/Tokyo')->startOfMonth());
                $originId = (string) Str::uuid7();
                if ($operation === 'rank') {
                    $manualId = DB::table('member_rank_manual_changes')->insertGetId([
                        'public_id' => $originId, 'user_id' => $user->id, 'admin_id' => $admin->id,
                        'kind' => $request['kind'], 'reason_text' => $request['reason'], 'from_level' => $state->level,
                        'to_level' => $this->level($request['rank']), 'skip_count' => $request['skip_count'],
                        'grant_bonus' => $request['grant_bonus'], 'bonus_amount' => $difference['total_bonus'],
                        'setting_revision' => $version['revision'], 'request_id' => $context->requestId,
                        'occurred_at' => V2DatabaseTimestamp::format($instant),
                    ]);
                    DB::table('member_rank_changes')->insert([
                        'user_id' => $user->id, 'from_level' => $state->level, 'to_level' => $this->level($request['rank']),
                        'reason' => 'manual', 'month' => $difference['month'], 'rank_points' => $difference['after_points'],
                        'setting_revision' => $version['revision'], 'manual_change_id' => $manualId,
                        'occurred_at' => V2DatabaseTimestamp::format($instant),
                    ]);
                    DB::table('member_rank_states')->where('user_id', $user->id)->update([
                        'level' => $this->level($request['rank']), 'baseline_level' => $this->level($request['rank']),
                        'skip_count' => $request['skip_count'], 'transferred_at' => $state->transferred_at
                            ?? ($request['kind'] === 'transfer' ? V2DatabaseTimestamp::format($instant) : null),
                    ]);
                    if ($request['grant_bonus']) {
                        $this->accounting->consumeReachedEntitlements((int) $user->id, $this->level($request['rank']),
                            $difference['month'], $difference['after_points'], $version, 'manual_rank', $originId, null, $instant);
                    }
                } else {
                    DB::table('member_rank_month_totals')->insertOrIgnore(['user_id' => $user->id, 'month' => $difference['month']]);
                    DB::table('member_rank_month_totals')->where('user_id', $user->id)->where('month', $difference['month'])
                        ->lockForUpdate()->firstOrFail();
                    $entryId = DB::table('member_rank_point_entries')->insertGetId([
                        'public_id' => $originId, 'user_id' => $user->id, 'month' => $difference['month'], 'admin_id' => $admin->id,
                        'reason' => 'manual_rank_points', 'reason_text' => $request['reason'],
                        'requested_delta' => $request['delta'], 'applied_delta' => $request['delta'],
                        'granted_at' => V2DatabaseTimestamp::format($instant), 'recorded_at' => V2DatabaseTimestamp::format($instant),
                    ]);
                    DB::table('member_rank_month_totals')->where('user_id', $user->id)->where('month', $difference['month'])
                        ->update(['points' => $difference['after_points']]);
                    if ($request['delta'] > 0) {
                        $this->corrections->applyIncrease((int) $user->id, $entryId, $version, $instant);
                    } else {
                        $this->corrections->applyDecrease((int) $user->id, $entryId, $version, $instant);
                    }
                }
                DB::table('member_rank_states')->where('user_id', $user->id)->update([
                    'revision' => DB::raw('revision + 1'), 'updated_at' => V2DatabaseTimestamp::format($instant),
                ]);
                $this->audit->record($operation === 'rank' ? 'member_rank.user.changed' : 'member_rank.points.adjusted', [
                    'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value, 'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash, 'target_type' => 'user', 'target_public_id' => $publicId,
                    'reason_text' => $request['reason'], 'reason_code' => $request['kind'] ?? 'manual_rank_points', 'outcome' => 'success',
                    'before' => ['rank' => $difference['before_rank'], 'points' => $difference['before_points'], 'skip_count' => $difference['before_skip']],
                    'after' => ['rank' => $difference['after_rank'], 'points' => $difference['after_points'], 'skip_count' => $difference['after_skip']],
                    'metadata' => ['origin_public_id' => $originId, 'month' => $difference['month'], 'setting_revision' => $version['revision'],
                        'bonus_amount' => $difference['total_bonus'], 'requested_delta' => $request['delta'] ?? null,
                        'applied_delta' => $request['delta'] ?? null,
                        'recovered_amount' => array_sum(array_column($difference['recoveries'], 'recovered_amount')),
                        'shortfall_amount' => array_sum(array_column($difference['recoveries'], 'shortfall_amount'))],
                ]);
                $this->idempotency->complete($claim->record, 'user', $publicId, ['data' => $difference]);

                return ['data' => $difference, 'idempotent_replay' => false];
            });
        } catch (V2PointException $exception) {
            if (in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)) {
                throw new V2MemberRankException($exception->getMessage(), 409, 'リクエストキーを確認してください。');
            }
            throw $exception;
        }
    }

    private function difference(int $userId, string $operation, array $request, CarbonImmutable $instant): array
    {
        $projection = $this->resolver->project($userId, $instant);
        $month = $instant->setTimezone('Asia/Tokyo')->startOfMonth();
        $state = DB::table('member_rank_states')->where('user_id', $userId)->first();
        $version = $this->settings->effectiveAt($month);
        $total = $projection['rank_points'];
        $after = $total;
        if ($operation === 'points') {
            if (($request['delta'] < 0 && $total < -$request['delta']) || ($request['delta'] > 0 && $total > PHP_INT_MAX - $request['delta'])) {
                throw new V2MemberRankException('MEMBER_RANK_POINTS_OUT_OF_RANGE', 422, '調整後のランクポイントが範囲外です。');
            }
            $after += $request['delta'];
        }
        $reached = $this->corrections->reachedLevel($after, $version);
        $afterLevel = $operation === 'rank' ? $this->level($request['rank'])
            : ($request['delta'] > 0 ? max($projection['level'], $reached)
                : min($projection['level'], max($projection['baseline_level'], $reached)));
        if ($operation === 'rank' && $afterLevel < $projection['level'] && $request['grant_bonus']) {
            throw $this->invalid();
        }
        $slots = DB::table('member_rank_entitlements')->where('user_id', $userId)->whereNotNull('achievement_id')->pluck('achievement_id', 'level')->all();
        $grants = [];
        $excluded = [];
        $grantTo = $operation === 'rank' ? ($request['grant_bonus'] ? $afterLevel : 1) : ($request['delta'] > 0 ? $reached : 1);
        for ($level = 2; $level <= $grantTo; $level++) {
            if (isset($slots[$level])) {
                $excluded[] = V2MemberRankSettingsService::CODES[$level - 1];
            } else {
                $grants[] = $this->effect($level, $version['ranks'][$level - 1]['promotion_bonus']);
            }
        }
        $recoveries = [];
        $available = $this->corrections->spendableFree($userId, $instant);
        if ($operation === 'points' && $request['delta'] < 0) {
            foreach ($this->corrections->recoverable($userId, $month->toDateString(), $after) as $achievement) {
                $recovered = min($available, (int) $achievement->bonus_amount);
                $available -= $recovered;
                $recoveries[] = $this->effect((int) $achievement->level, (int) $achievement->bonus_amount, $recovered,
                    (int) $achievement->bonus_amount - $recovered);
            }
        }
        $difference = [
            'month' => $month->toDateString(), 'expected_revision' => (int) ($state?->revision ?? 0), 'setting_revision' => $version['revision'],
            'before_rank' => V2MemberRankSettingsService::CODES[$projection['level'] - 1],
            'after_rank' => V2MemberRankSettingsService::CODES[$afterLevel - 1], 'before_points' => $total, 'after_points' => $after,
            'before_skip' => $projection['skip_count'], 'after_skip' => $operation === 'rank' ? $request['skip_count'] : $projection['skip_count'],
            'grants' => $grants, 'excluded_ranks' => $excluded, 'recoveries' => $recoveries,
            'total_bonus' => array_sum(array_column($grants, 'amount')), 'last_transfer' => $this->read->lastTransfer($userId),
        ];

        return [...$difference, 'preview_token' => hash('sha256', json_encode([$userId, $request, $difference, $slots,
            $this->corrections->spendableFree($userId, $instant)], JSON_THROW_ON_ERROR))];
    }

    private function validate(string $operation, array $input, bool $write): array
    {
        if (! in_array($operation, ['rank', 'points'], true)) {
            throw $this->invalid();
        }
        $keys = $operation === 'rank' ? ['rank', 'kind', 'reason', 'skip_count', 'grant_bonus'] : ['delta', 'reason'];
        $allowed = $write ? [...$keys, 'expected_revision', 'preview_token', ...($operation === 'rank' ? ['confirmed_transfer_id'] : [])] : $keys;
        if (array_diff($keys, array_keys($input)) !== [] || array_diff(array_keys($input), $allowed) !== []) {
            throw $this->invalid();
        }
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500
            || preg_match('/[^\p{Z}\s]/u', $reason) !== 1 || preg_match('/(?![\t\r\n])\p{Cc}/u', $reason)) {
            throw $this->invalid();
        }
        if ($write && (! is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0
            || ! is_string($input['preview_token'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $input['preview_token']) !== 1)) {
            throw $this->invalid();
        }
        if ($operation === 'rank') {
            if (! in_array($input['rank'], V2MemberRankSettingsService::CODES, true) || ! in_array($input['kind'], self::KINDS, true)
                || ! is_int($input['skip_count']) || $input['skip_count'] < 0 || $input['skip_count'] > 12 || ! is_bool($input['grant_bonus'])
                || (isset($input['confirmed_transfer_id']) && (! is_string($input['confirmed_transfer_id']) || ! Str::isUuid($input['confirmed_transfer_id'])))) {
                throw $this->invalid();
            }
        } elseif (! is_int($input['delta']) || $input['delta'] === 0 || abs($input['delta']) > 9007199254740991) {
            throw $this->invalid();
        }

        return array_intersect_key($input, array_flip($keys));
    }

    private function requireWritable(object $user): void
    {
        if ($user->state !== 'active') {
            throw new V2MemberRankException('MEMBER_RANK_USER_UNAVAILABLE', 409, '有効な会員のみ変更できます。');
        }
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            throw new V2MemberRankException('MEMBER_RANK_NOT_ENABLED', 409, '会員ランク機能は運用開始前です。');
        }
    }

    private function level(string $code): int
    {
        return array_search($code, V2MemberRankSettingsService::CODES, true) + 1;
    }

    private function effect(int $level, int $amount, int $recovered = 0, int $shortfall = 0): array
    {
        return ['rank' => V2MemberRankSettingsService::CODES[$level - 1], 'amount' => $amount,
            'recovered_amount' => $recovered, 'shortfall_amount' => $shortfall, 'point_type' => 'free', 'expires_in_days' => 180];
    }

    private function invalid(): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_INPUT_INVALID', 422, '入力内容を確認してください。');
    }
}
