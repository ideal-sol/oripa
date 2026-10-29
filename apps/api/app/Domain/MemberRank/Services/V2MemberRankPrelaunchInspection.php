<?php

namespace App\Domain\MemberRank\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPrelaunchInspection
{
    public const FINANCIAL_TABLES = [
        'payment_commercial_classifications', 'payments', 'payment_point_grants', 'payment_provider_events', 'payment_provider_operations',
        'payment_adjustments', 'payment_adjustment_point_operations',
        'point_operations', 'wallets', 'point_lots', 'point_ledger_entries',
        'draw_requests', 'draw_results',
        'member_rank_setting_versions', 'member_rank_setting_values', 'member_rank_states',
        'member_rank_point_entries', 'member_rank_month_totals', 'member_rank_changes',
        'member_rank_achievements', 'member_rank_entitlements', 'member_rank_purchase_bonuses',
        'member_rank_bonus_recoveries', 'member_rank_manual_changes',
        'member_rank_payment_adjustments', 'member_rank_monthly_jobs',
    ];

    public function inspect(?string $authorizationId = null): array
    {
        $connection = $this->connection();
        return $connection->transaction(function () use ($connection, $authorizationId): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->observe($connection, $authorizationId);
        }, 1);
    }

    public function inspectQuiescent(): array
    {
        $connection = $this->connection();
        try {
            return $connection->transaction(function () use ($connection): array {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->statement('LOCK TABLE member_rank_settings IN ACCESS EXCLUSIVE MODE NOWAIT');
                $connection->statement('LOCK TABLE '.implode(', ', self::FINANCIAL_TABLES).' IN SHARE ROW EXCLUSIVE MODE NOWAIT');

                return [...$this->observe($connection), 'status' => 'QUIESCENT_OBSERVATION_ONLY',
                    'fenced_tables' => ['member_rank_settings', ...self::FINANCIAL_TABLES],
                    'fence_released_before_return' => true];
            }, 1);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new \RuntimeException('PRELAUNCH_INSPECTION_BUSY', 0, $exception);
            }
            throw $exception;
        }
    }

    public function inspectClosedAdmission(?string $authorizationId = null): array
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 1 || ! $connection->selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid()
                AND locktype = 'advisory' AND classid = 740022 AND objid = 1 AND objsubid = 2
                AND granted AND mode = 'ExclusiveLock') AS closed"
        )->closed) {
            throw new \LogicException('Final inspection requires exclusive financial admission.');
        }

        return $this->observe($connection, $authorizationId);
    }

    private function connection(): Connection
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            throw new \LogicException('Prelaunch inspection requires its own PostgreSQL read-only snapshot.');
        }

        return $connection;
    }

    private function observe(Connection $connection, ?string $authorizationId = null): array
    {
        $setting = $connection->table('member_rank_settings')->where('id', 1)->sole();
        $protected = $this->protectedUsers($connection);
        $historyApproved = (bool) $connection->selectOne(
            'SELECT v2_prelaunch_history_approved(?::uuid) AS approved', [$authorizationId]
        )->approved;
        $counts = [
            'users' => $connection->table('users')->count(),
            'protected_applicants' => (clone $protected)->count(),
            'protected_payments' => $connection->table('payments')->whereIn('user_id', clone $protected)->count(),
            'unapproved_origins' => $connection->table('payments')->whereRaw('NOT v2_prelaunch_test_origin(id)')->count(),
            'protected_succeeded_payments' => $connection->table('payments')
                ->whereIn('user_id', clone $protected)->where('status', 'succeeded')->count(),
            'protected_paid_grants' => $connection->table('payment_point_grants as grants')
                ->join('payments as payments', 'payments.id', '=', 'grants.payment_id')
                ->whereIn('payments.user_id', clone $protected)->count(),
            'protected_draw_requests' => $connection->table('draw_requests')
                ->whereIn('user_id', clone $protected)->count(),
            'protected_other_point_operations' => $connection->table('point_operations')
                ->whereIn('user_id', clone $protected)->whereNotIn('id', $connection->table('pre_registration_grants')
                    ->select('point_operation_id'))->count(),
            'protected_preregistration_grants' => $connection->table('pre_registration_grants')
                ->whereIn('user_id', clone $protected)->count(),
            'pending_payments' => $connection->table('payments')
                ->whereNotIn('status', ['succeeded', 'failed', 'canceled', 'expired'])
                ->whereRaw('NOT v2_prelaunch_pending_payment_allowed(id, ?::boolean)', [$historyApproved])->count(),
            'pending_provider_events' => $connection->table('payment_provider_events')
                ->whereNotIn('processing_status', ['processed', 'ignored'])
                ->whereRaw('NOT v2_prelaunch_test_event(id)')
                ->whereNotExists(function (Builder $query): void {
                    $query->selectRaw('1')->from('payment_provider_event_attempts as attempt')
                        ->whereColumn('attempt.payment_provider_event_id', 'payment_provider_events.id')
                        ->where('attempt.outcome', 'success')->whereNotNull('attempt.completed_at')
                        ->whereNotExists(function (Builder $later): void {
                            $later->selectRaw('1')->from('payment_provider_event_attempts as later')
                                ->whereColumn('later.payment_provider_event_id', 'attempt.payment_provider_event_id')
                                ->whereColumn('later.attempt_no', '>', 'attempt.attempt_no');
                        });
                })->count(),
            'unassociated_provider_events' => $connection->table('payment_provider_events')
                ->whereNull('payment_id')->count(),
            'pending_provider_operations' => $connection->table('payment_provider_operations')
                ->whereNotIn('status', ['succeeded', 'failed'])->count(),
            'pending_fincode_attempts' => $connection->table('fincode_payment_attempts')
                ->whereNotIn('status', ['completed', 'failed'])
                ->whereRaw('NOT v2_prelaunch_test_attempt(id, ?::boolean)', [$historyApproved])->count(),
            'pending_adjustments' => $connection->table('payment_adjustments')
                ->whereNotIn('status', ['succeeded', 'failed', 'canceled'])->count(),
            'paid_grants' => $connection->table('payment_point_grants')->count(),
            'succeeded_without_grant' => $connection->table('payments as payments')
                ->leftJoin('payment_point_grants as grants', 'grants.payment_id', '=', 'payments.id')
                ->where('payments.status', 'succeeded')->whereNull('grants.id')->count(),
            'invalid_paid_origins' => $this->invalidPaidOrigins($connection),
            'financial_discrepancies' => $this->financialDiscrepancies($connection),
            'unresolved_reconciliations' => $connection->table('point_reconciliation_discrepancies')->where('resolved', false)->count(),
        ];
        $rankRows = [];
        foreach (['member_rank_states', 'member_rank_point_entries', 'member_rank_month_totals',
            'member_rank_changes', 'member_rank_achievements', 'member_rank_entitlements',
            'member_rank_purchase_bonuses', 'member_rank_bonus_recoveries', 'member_rank_manual_changes',
            'member_rank_payment_adjustments', 'member_rank_monthly_jobs'] as $table) {
            $rankRows[$table] = $connection->table($table)->count();
        }
        $allowedZeroAdjustments = $connection->table('member_rank_payment_adjustments')
            ->whereRaw('v2_prelaunch_zero_adjustment(id)')->count();
        $holds = [];
        if ($authorizationId !== null && ! $historyApproved && $connection->table('member_rank_prelaunch_authorizations')
            ->where('public_id', $authorizationId)->whereNotNull('history_scope')->exists()) {
            $holds[] = 'APPROVED_HISTORY_SCOPE_CHANGED';
        }
        // Preserve raw activity counts in the observation. A complete immutable
        // approval, rather than user identity or a count alone, removes a HOLD.
        if ($setting->enabled || $setting->activated_at !== null) {
            $holds[] = 'ALREADY_ACTIVATED';
        }
        foreach (['protected_payments', 'unapproved_origins', 'protected_succeeded_payments', 'protected_paid_grants', 'protected_draw_requests',
            'protected_other_point_operations', 'pending_payments', 'pending_provider_events',
            'unassociated_provider_events', 'pending_provider_operations', 'pending_fincode_attempts', 'pending_adjustments',
            'succeeded_without_grant', 'invalid_paid_origins', 'financial_discrepancies', 'unresolved_reconciliations'] as $name) {
            if ($counts[$name] > 0) {
                if ($historyApproved && in_array($name, ['protected_draw_requests', 'protected_other_point_operations'], true)) {
                    continue;
                }
                $holds[] = strtoupper($name);
            }
        }
        if (array_sum($rankRows) - $allowedZeroAdjustments > 0) {
            $holds[] = 'EXISTING_RANK_BUSINESS_STATE';
        }

        return [
            'status' => 'OBSERVATION_ONLY', 'activation_authorized' => false,
            'snapshot_started_at' => $connection->selectOne('SELECT transaction_timestamp()::text AS instant')->instant,
            'isolation' => $connection->selectOne('SHOW transaction_isolation')->transaction_isolation,
            'read_only' => $connection->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on',
            'revision' => (int) $setting->revision, 'enabled' => (bool) $setting->enabled,
            'protection_basis' => 'pre_registered_at_or_preregistration_history_without_date_filter',
            'counts' => $counts, 'history_scope_approved' => $historyApproved,
            'allowed_zero_rank_adjustments' => $allowedZeroAdjustments, 'rank_rows' => $rankRows, 'data_holds' => $holds,
            'unproven_gates' => ['SITE_BOUND_HUMAN_AUTHORITY', 'EXACT_CUTOVER',
                'FINANCIAL_ADMISSION_AND_DRAIN', 'NEW_PROVIDER_INTEGRATION', 'HUMAN_ACTIVATION_GO'],
        ];
    }

    private function protectedUsers(Connection $connection): Builder
    {
        return $connection->table('users')->select('id')->where(function (Builder $query) use ($connection): void {
            $query->whereNotNull('pre_registered_at')
                ->orWhereIn('id', $connection->table('pre_registration_targets')->select('user_id'))
                ->orWhereIn('id', $connection->table('pre_registration_grants')->select('user_id'));
        });
    }

    private function invalidPaidOrigins(Connection $connection): int
    {
        return $connection->table('payment_point_grants as grants')
            ->leftJoin('payments as payments', 'payments.id', '=', 'grants.payment_id')
            ->leftJoin('point_operations as operations', 'operations.id', '=', 'grants.point_operation_id')
            ->where(function (Builder $query): void {
                $query->whereNull('payments.id')->orWhereNull('operations.id')
                    ->orWhereNull('operations.source_id')
                    ->orWhere('payments.status', '<>', 'succeeded')
                    ->orWhereColumn('payments.amount', '<>', 'payments.paid_point_amount')
                    ->orWhereColumn('payments.user_id', '<>', 'operations.user_id')
                    ->orWhereColumn('payments.id', '<>', 'operations.source_id')
                    ->orWhere('operations.source_type', '<>', 'payment')
                    ->orWhere('operations.operation_type', '<>', 'payment_grant')
                    ->orWhereNull('payments.points_granted_at')
                    ->orWhereColumn('payments.points_granted_at', '<>', 'grants.granted_at')
                    ->orWhereColumn('operations.occurred_at', '<>', 'grants.granted_at');
            })->orWhereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('point_lots as lot')
                    ->join('point_ledger_entries as ledger', 'ledger.point_lot_id', '=', 'lot.id')
                    ->whereColumn('lot.grant_operation_id', 'grants.point_operation_id')
                    ->whereColumn('lot.user_id', 'payments.user_id')->where('lot.point_type', 'paid')
                    ->whereColumn('lot.granted_amount', 'payments.amount')
                    ->whereColumn('ledger.point_operation_id', 'grants.point_operation_id')
                    ->whereColumn('ledger.user_id', 'payments.user_id')->where('ledger.entry_type', 'grant')
                    ->where('ledger.point_type', 'paid')->whereColumn('ledger.amount_delta', 'payments.amount');
            })->count();
    }

    private function financialDiscrepancies(Connection $connection): int
    {
        return (int) $connection->selectOne(<<<'SQL'
            WITH lots AS (
                SELECT user_id, point_type, sum(remaining_amount) AS balance, sum(reserved_amount) AS reserved
                FROM point_lots GROUP BY user_id, point_type
            ), ledger AS (
                SELECT user_id, point_type, sum(amount_delta) AS balance
                FROM point_ledger_entries GROUP BY user_id, point_type
            )
            SELECT count(*) AS discrepancies FROM wallets wallet
            CROSS JOIN (VALUES ('paid'), ('free')) AS kind(point_type)
            LEFT JOIN lots ON lots.user_id = wallet.user_id AND lots.point_type = kind.point_type
            LEFT JOIN ledger ON ledger.user_id = wallet.user_id AND ledger.point_type = kind.point_type
            WHERE (CASE kind.point_type WHEN 'paid' THEN wallet.paid_balance ELSE wallet.free_balance END)
                    IS DISTINCT FROM COALESCE(lots.balance, 0)
                OR (CASE kind.point_type WHEN 'paid' THEN wallet.paid_balance ELSE wallet.free_balance END)
                    IS DISTINCT FROM COALESCE(ledger.balance, 0)
                OR (CASE kind.point_type WHEN 'paid' THEN wallet.paid_reserved_balance ELSE wallet.free_reserved_balance END)
                    IS DISTINCT FROM COALESCE(lots.reserved, 0)
            SQL)->discrepancies;
    }
}
