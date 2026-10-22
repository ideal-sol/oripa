<?php

namespace App\Domain\MemberRank\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPrelaunchInspection
{
    public const FINANCIAL_TABLES = [
        'payments', 'payment_point_grants', 'payment_provider_events', 'payment_provider_operations',
        'payment_adjustments', 'payment_adjustment_point_operations',
        'point_operations', 'wallets', 'point_lots', 'point_ledger_entries',
        'draw_requests', 'draw_results',
        'member_rank_setting_versions', 'member_rank_setting_values', 'member_rank_states',
        'member_rank_point_entries', 'member_rank_month_totals', 'member_rank_changes',
        'member_rank_achievements', 'member_rank_entitlements', 'member_rank_purchase_bonuses',
        'member_rank_bonus_recoveries', 'member_rank_manual_changes',
        'member_rank_payment_adjustments', 'member_rank_monthly_jobs',
    ];

    public function inspect(): array
    {
        $connection = $this->connection();
        return $connection->transaction(function () use ($connection): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->observe($connection);
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

    private function connection(): Connection
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            throw new \LogicException('Prelaunch inspection requires its own PostgreSQL read-only snapshot.');
        }

        return $connection;
    }

    private function observe(Connection $connection): array
    {
        $setting = $connection->table('member_rank_settings')->where('id', 1)->sole();
        $protected = $this->protectedUsers($connection);
        $counts = [
            'users' => $connection->table('users')->count(),
            'protected_applicants' => (clone $protected)->count(),
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
                ->whereNotIn('status', ['succeeded', 'failed', 'canceled', 'expired'])->count(),
            'pending_provider_events' => $connection->table('payment_provider_events')
                ->whereNotIn('processing_status', ['processed', 'ignored'])->count(),
            'unassociated_provider_events' => $connection->table('payment_provider_events')
                ->whereNull('payment_id')->count(),
            'pending_provider_operations' => $connection->table('payment_provider_operations')
                ->whereNotIn('status', ['succeeded', 'failed'])->count(),
            'pending_adjustments' => $connection->table('payment_adjustments')
                ->whereNotIn('status', ['succeeded', 'failed', 'canceled'])->count(),
            'paid_grants' => $connection->table('payment_point_grants')->count(),
            'succeeded_without_grant' => $connection->table('payments as payments')
                ->leftJoin('payment_point_grants as grants', 'grants.payment_id', '=', 'payments.id')
                ->where('payments.status', 'succeeded')->whereNull('grants.id')->count(),
            'invalid_paid_origins' => $this->invalidPaidOrigins($connection),
        ];
        $rankRows = [];
        foreach (['member_rank_states', 'member_rank_point_entries', 'member_rank_month_totals',
            'member_rank_changes', 'member_rank_achievements', 'member_rank_entitlements',
            'member_rank_purchase_bonuses', 'member_rank_bonus_recoveries'] as $table) {
            $rankRows[$table] = $connection->table($table)->count();
        }
        $holds = [];
        if ($setting->enabled || $setting->activated_at !== null) {
            $holds[] = 'ALREADY_ACTIVATED';
        }
        foreach (['protected_succeeded_payments', 'protected_paid_grants', 'protected_draw_requests',
            'protected_other_point_operations', 'pending_payments', 'pending_provider_events',
            'unassociated_provider_events', 'pending_provider_operations', 'pending_adjustments',
            'succeeded_without_grant', 'invalid_paid_origins'] as $name) {
            if ($counts[$name] > 0) {
                $holds[] = strtoupper($name);
            }
        }
        if (array_sum($rankRows) > 0) {
            $holds[] = 'EXISTING_RANK_BUSINESS_STATE';
        }

        return [
            'status' => 'OBSERVATION_ONLY', 'activation_authorized' => false,
            'snapshot_started_at' => $connection->selectOne('SELECT transaction_timestamp()::text AS instant')->instant,
            'isolation' => $connection->selectOne('SHOW transaction_isolation')->transaction_isolation,
            'read_only' => $connection->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on',
            'revision' => (int) $setting->revision, 'enabled' => (bool) $setting->enabled,
            'protection_basis' => 'pre_registered_at_or_preregistration_history_without_date_filter',
            'counts' => $counts, 'rank_rows' => $rankRows, 'data_holds' => $holds,
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
            })->count();
    }
}
