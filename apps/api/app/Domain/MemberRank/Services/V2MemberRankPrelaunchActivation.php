<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2FinancialAdmission;
use App\Domain\Point\Services\V2PointIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2MemberRankPrelaunchActivation
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2FinancialAdmission $admission,
        private readonly V2MemberRankPrelaunchInspection $inspection,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function activate(V2AdminAuthorizationContext $context, string $key, array $input): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Prelaunch publication requires its own transaction.');
        }
        if (array_diff(array_keys($input), ['authorization_id', 'expected_revision']) !== []
            || ! is_string($input['authorization_id'] ?? null) || ! Str::isUuid($input['authorization_id'])
            || ! is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1
            || ! preg_match('/\A[\x21-\x7e]{16,128}\z/', $key)) {
            throw new V2MemberRankException('MEMBER_RANK_SETTINGS_INVALID', 422, '有効な有効化リクエストを指定してください。');
        }
        $this->authorize($context);
        try {
            return DB::transaction(function () use ($context, $key, $input): array {
                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                DB::statement("SET LOCAL lock_timeout = '100ms'");
                $this->admission->close();
                DB::table('admins')->where('id', $context->adminId)->lock('FOR SHARE NOWAIT')->sole();
                DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)
                    ->lock('FOR SHARE NOWAIT')->sole();
                $admin = $this->authorize($context);
                $claim = $this->idempotency->claim('member_rank.prelaunch.activate', 'admin', $admin->public_id, $key, $input);
                if ($claim->replay) {
                    return ['data' => $claim->record->response_data['data'], 'idempotent_replay' => true];
                }
                $setting = DB::table('member_rank_settings')->where('id', 1)->lock('FOR UPDATE NOWAIT')->sole();
                if ($setting->enabled || $setting->activated_at !== null) {
                    throw new V2MemberRankException('MEMBER_RANK_ALREADY_ACTIVATED', 409, '会員ランクは有効化済みです。');
                }
                if ((int) $setting->revision !== $input['expected_revision']) {
                    throw new V2MemberRankException('MEMBER_RANK_SETTINGS_REVISION_CONFLICT', 409, '最新の設定を取得してください。');
                }
                $authority = DB::table('member_rank_prelaunch_authorizations')
                    ->where('public_id', $input['authorization_id'])->where('setting_public_id', $setting->public_id)
                    ->where('expected_revision', $setting->revision)->first();
                if ($authority === null) {
                    throw $this->hold('SITE_BOUND_AUTHORITY_REQUIRED');
                }
                $observation = $this->inspection->inspectClosedAdmission($authority->public_id);
                if ($observation['data_holds'] !== []) {
                    throw $this->hold(implode(',', $observation['data_holds']));
                }
                $digest = DB::selectOne('SELECT v2_member_rank_prelaunch_manifest(?::text) AS digest', [$authority->history_approval_digest])->digest;
                if (! hash_equals($authority->origin_digest, $digest)) {
                    throw $this->hold('APPROVED_ORIGIN_MANIFEST_CHANGED');
                }
                if (! DB::selectOne("SELECT pg_try_advisory_xact_lock(hashtextextended('v2_audit_chain', 0)) AS acquired")->acquired) {
                    throw $this->busy();
                }
                $count = DB::table('payments')->count();
                DB::statement('INSERT INTO member_rank_prelaunch_excluded_origins
                    (payment_id, run_id, grant_id, point_operation_id, granted_at)
                    SELECT payment.id, 1, grant_row.id, grant_row.point_operation_id, grant_row.granted_at
                    FROM payments payment LEFT JOIN payment_point_grants grant_row ON grant_row.payment_id = payment.id');
                $this->authorize($context);
                $instant = DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant;
                DB::table('member_rank_prelaunch_runs')->insert([
                    'id' => 1, 'public_id' => $authority->public_id, 'activated_at' => $instant,
                    'setting_revision' => $setting->revision, 'excluded_origin_count' => $count,
                    'origin_digest' => $digest, 'admin_id' => $admin->id,
                ]);
                DB::table('financial_admission_generations')->where('id', 1)->update(['generation' => 1]);
                DB::table('member_rank_settings')->where('id', 1)->update(['enabled' => true, 'activated_at' => $instant]);
                $data = ['authorization_id' => $authority->public_id, 'enabled' => true,
                    'activated_at' => CarbonImmutable::parse($instant)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'revision' => (int) $setting->revision, 'excluded_origin_count' => $count];
                $this->audit->record('member_rank.prelaunch.activated', [
                    'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value, 'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'member_rank_setting', 'target_public_id' => $setting->public_id,
                    'before' => ['enabled' => false],
                    'after' => ['enabled' => true, 'activated_at' => $data['activated_at'],
                        'revision' => $data['revision'], 'excluded_origin_count' => $count, 'run_id' => $authority->public_id],
                    'metadata' => ['authority_sha256' => $authority->authority_sha256, 'origin_digest' => $digest,
                        'history_evidence_sha256' => $authority->history_evidence_sha256,
                        'history_approval_digest' => $authority->history_approval_digest],
                ]);
                $this->idempotency->complete($claim->record, 'member_rank_setting', $setting->public_id, ['data' => $data]);

                return ['data' => $data, 'idempotent_replay' => false];
            }, 1);
        } catch (V2PointException $exception) {
            throw new V2MemberRankException($exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS', 409, '同じリクエストキーの処理を確認してください。');
        } catch (\PDOException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['40001', '40P01', '55P03'], true)) {
                throw $this->busy();
            }
            throw $exception;
        }
    }

    private function authorize(V2AdminAuthorizationContext $context): \App\Models\V2\Admin
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageMemberRankSettings);
        if ($admin->role !== V2AdminRole::Owner) {
            throw new V2AuthenticationException('AUTHORIZATION_DENIED', 403, 'Prelaunch activation requires an Owner.');
        }
        if (! $this->authorization->isFresh($this->authorization->validSessionForReauthentication($context))) {
            throw new V2AuthenticationException('FRESH_MFA_REQUIRED', 403, 'Fresh MFA within five minutes is required.');
        }

        return $admin;
    }

    private function hold(string $reason): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_PRELAUNCH_HOLD', 409, $reason);
    }

    private function busy(): V2MemberRankException
    {
        return new V2MemberRankException('MEMBER_RANK_ACTIVATION_BUSY', 409, '関連する処理の完了後に再試行してください。');
    }
}
