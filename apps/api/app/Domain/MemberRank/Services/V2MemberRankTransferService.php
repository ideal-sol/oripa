<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankTransferService
{
    public function transferred(int $userId): bool
    {
        $status = DB::table('member_rank_transfer_allowances')->where('user_id', $userId)->value('status');

        return $status === null
            ? DB::table('member_rank_states')->where('user_id', $userId)->whereNotNull('transferred_at')->exists()
            : $status === 'transferred';
    }

    public function history(int $userId, array $filters): array
    {
        if (array_diff(array_keys($filters), ['cursor']) !== [] || (isset($filters['cursor']) && ! is_string($filters['cursor']))) {
            throw $this->invalid();
        }
        $cursor = app(\App\Domain\Reporting\Services\V2ReportingCursor::class);
        $before = $cursor->decode($filters['cursor'] ?? null);
        $rows = DB::table('member_rank_transfer_histories as h')->leftJoin('admins as a', 'a.id', '=', 'h.admin_id')
            ->where('h.user_id', $userId)->when($before !== null, fn ($q) => $q->where('h.id', '<', $before))
            ->orderByDesc('h.id')->limit(21)->get(['h.*', 'a.public_id as actor_id']);
        $page = $rows->take(20);

        return ['items' => $page->map(fn ($row) => [
            'id' => $row->public_id, 'actor_id' => $row->actor_id, 'reason' => $row->reason_text,
            'before' => json_decode($row->before_value, true, flags: JSON_THROW_ON_ERROR),
            'after' => json_decode($row->after_value, true, flags: JSON_THROW_ON_ERROR),
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)->utc()->toIso8601String(),
        ])->all(), 'next_cursor' => $rows->count() > 20 ? $cursor->encode((int) $page->last()->id) : null];
    }

    public function read(int $userId): array
    {
        $row = DB::table('member_rank_transfer_allowances')->where('user_id', $userId)->first();

        return ['status' => $row?->status ?? 'none', 'equivalent_level' => $row?->equivalent_level === null ? null : (int) $row->equivalent_level,
            'points' => (int) ($row?->points ?? 0), 'revision' => (int) ($row?->revision ?? 0)];
    }

    public function write(V2AdminAuthorizationContext $context, string $publicId, array $input, string $key): array
    {
        $admin = app(V2AdminFreshMfaAuthorizer::class)->authorizePermission($context, V2Permission::ManageMemberRankUser);
        if (array_diff(array_keys($input), ['status', 'equivalent_level', 'points', 'expected_revision', 'reason']) !== []
            || ! is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0
            || preg_match('/\A[\x21-\x7e]{16,128}\z/', $key) !== 1) {
            throw $this->invalid();
        }
        $this->validate($input);

        try {
            return app(V2PointTransactionRunner::class)->run(function () use ($context, $admin, $publicId, $input, $key): array {
            app(V2AdminFreshMfaAuthorizer::class)->authorizePermission($context, V2Permission::ManageMemberRankUser);
            $idempotency = app(V2PointIdempotencyService::class);
            $claim = $idempotency->claim('member_rank.transfer', 'admin', $admin->public_id, $key, ['user_id' => $publicId, ...$input]);
            if ($claim->replay) {
                return ['data' => $claim->record->response_data['data'], 'idempotent_replay' => true];
            }
            $user = DB::table('users')->where('public_id', $publicId)->lockForUpdate()->first();
            if ($user === null) {
                throw new V2MemberRankException('ADMIN_USER_NOT_FOUND', 404, '会員が見つかりません。');
            }
            if ($user->state !== 'active' || ! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
                throw new V2MemberRankException('MEMBER_RANK_USER_UNAVAILABLE', 409, '運用開始済みの有効な会員のみ変更できます。');
            }
            $instant = CarbonImmutable::now()->startOfSecond();
            DB::table('member_rank_states')->insertOrIgnore([
                'user_id' => $user->id, 'baseline_month' => $instant->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString(),
                'created_at' => V2DatabaseTimestamp::format($instant), 'updated_at' => V2DatabaseTimestamp::format($instant),
            ]);
            $history = $this->save((int) $user->id, $input, (int) $admin->id, $instant, $input['expected_revision'], $context);
            $version = app(V2MemberRankSettingsService::class)->effectiveAt($instant);
            app(V2MemberRankEventPromotionService::class)->apply((int) $user->id, 'transfer', $version, $instant, $history);
            $result = $this->read((int) $user->id);
            $idempotency->complete($claim->record, 'user', $publicId, ['data' => $result]);

            return ['data' => $result, 'idempotent_replay' => false];
            });
        } catch (V2PointException $exception) {
            if (in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)) {
                throw new V2MemberRankException($exception->getMessage(), 409, 'リクエストキーを確認してください。');
            }
            throw $exception;
        }
    }

    public function save(int $userId, array $input, ?int $adminId, CarbonImmutable $instant, ?int $expectedRevision = null, ?V2AdminAuthorizationContext $context = null): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Transfer requires the owning transaction.');
        }
        $this->validate($input);
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
        $state = DB::table('member_rank_states')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
        if ($user->state !== 'active' || $state->frozen_at !== null) {
            throw new V2MemberRankException('MEMBER_RANK_USER_UNAVAILABLE', 409, '有効な会員のみ変更できます。');
        }
        DB::table('member_rank_transfer_allowances')->where('user_id', $userId)->lockForUpdate()->first();
        $before = $this->read($userId);
        if ($expectedRevision !== null && $before['revision'] !== $expectedRevision) {
            throw new V2MemberRankException('MEMBER_RANK_REVISION_CONFLICT', 409, '移行欄が変更されました。再確認してください。');
        }
        $after = ['status' => $input['status'], 'equivalent_level' => $input['equivalent_level'],
            'points' => $input['points'], 'revision' => $before['revision'] + 1];
        $timestamp = V2DatabaseTimestamp::format($instant);
        DB::table('member_rank_transfer_allowances')->updateOrInsert(['user_id' => $userId], [
            ...$after, 'updated_by_admin_id' => $adminId, 'updated_at' => $timestamp,
            ...($before['revision'] === 0 ? ['created_at' => $timestamp] : []),
        ]);
        $history = DB::table('member_rank_transfer_histories')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'user_id' => $userId, 'revision' => $after['revision'],
            'admin_id' => $adminId, 'source' => $adminId === null ? 'backfill' : 'manual', 'reason_text' => $input['reason'],
            'before_value' => json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => $timestamp,
        ]);
        DB::table('member_rank_states')->where('user_id', $userId)->update([
            'revision' => DB::raw('revision + 1'), 'updated_at' => $timestamp,
            'transferred_at' => $state->transferred_at ?? ($after['status'] === 'transferred' ? $timestamp : null),
        ]);
        app(V2AuditLogService::class)->record('member_rank.transfer_allowance.updated', [
            'actor_type' => $adminId === null ? 'system' : 'admin',
            'actor_public_id' => $adminId === null ? null : DB::table('admins')->where('id', $adminId)->value('public_id'),
            'actor_role' => $context?->role->value, 'auth_realm' => $context === null ? null : 'admin',
            'request_id' => $context?->requestId, 'session_correlation_hash' => $context?->sessionCorrelationHash,
            'target_type' => 'user', 'target_public_id' => $user->public_id, 'reason_text' => $input['reason'],
            'before' => $before, 'after' => $after,
        ]);

        return $history;
    }

    private function validate(array $input): void
    {
        if (! in_array($input['status'] ?? null, ['none', 'transferred'], true)
            || ! is_int($input['points'] ?? null) || $input['points'] < 0 || $input['points'] > 9007199254740991
            || ! array_key_exists('equivalent_level', $input)
            || ($input['status'] === 'none' && ($input['points'] !== 0 || $input['equivalent_level'] !== null))
            || ($input['status'] === 'transferred' && (! is_int($input['equivalent_level']) || $input['equivalent_level'] < 1 || $input['equivalent_level'] > 7))
            || ! is_string($input['reason'] ?? null) || ! mb_check_encoding($input['reason'], 'UTF-8')
            || mb_strlen($input['reason']) > 500 || preg_match('/[^\p{Z}\s]/u', $input['reason']) !== 1
            || preg_match('/(?![\t\r\n])\p{Cc}/u', $input['reason'])) {
            throw $this->invalid();
        }
    }

    private function invalid(): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_INPUT_INVALID', 422, '入力内容を確認してください。');
    }
}
