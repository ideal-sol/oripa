<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\ValueObjects\V2ReportingPeriod;
use App\Models\V2\Admin;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2ReportingService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2AuditLogService $audit,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    /** @return array<string, mixed> */
    public function monthlySales(
        V2AdminAuthorizationContext $context,
        string $month
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $payment = DB::table('payments')
            ->where('status', 'succeeded')
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->first();
        $adjustments = DB::table('payment_adjustments')
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw(
                "COUNT(*) FILTER (WHERE type = 'refund' AND status = 'succeeded') AS refund_count"
            )
            ->selectRaw(
                "COALESCE(SUM(amount) FILTER (WHERE type = 'refund' AND status = 'succeeded'), 0) AS refund_amount"
            )
            ->selectRaw(
                "COUNT(*) FILTER (WHERE type = 'chargeback' AND status = 'succeeded') AS chargeback_count"
            )
            ->selectRaw(
                "COALESCE(SUM(amount) FILTER (WHERE type = 'chargeback' AND status = 'succeeded'), 0) AS chargeback_amount"
            )
            ->selectRaw(
                "COUNT(*) FILTER (WHERE type = 'chargeback_reversal') AS reversal_count"
            )
            ->selectRaw(
                "COUNT(*) FILTER (WHERE status IN ('requested','points_reserved','submitted','processing','manual_review')) AS pending_count"
            )
            ->first();
        $gross = (int) ($payment->amount ?? 0);
        $refund = (int) ($adjustments->refund_amount ?? 0);
        $chargeback = (int) ($adjustments->chargeback_amount ?? 0);
        $daily = DB::table('payments')
            ->where('status', 'succeeded')
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date AS business_date")
            ->selectRaw('COUNT(*) AS payment_count, SUM(amount) AS gross_amount')
            ->groupByRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date")
            ->orderBy('business_date')
            ->get()
            ->map(fn (object $row): array => [
                'business_date' => (string) $row->business_date,
                'payment_count' => (int) $row->payment_count,
                'gross_amount' => (int) $row->gross_amount,
            ])->all();

        $this->auditView($admin, $context, 'sales_monthly', $period->value);

        return [
            'month' => $period->value,
            'currency' => 'JPY',
            'basis' => 'operational_event_aggregation_not_accounting_recognition',
            'gross_sales' => ['count' => (int) ($payment->count ?? 0), 'amount' => $gross],
            'refunds' => [
                'count' => (int) ($adjustments->refund_count ?? 0),
                'amount' => $refund,
            ],
            'chargebacks' => [
                'count' => (int) ($adjustments->chargeback_count ?? 0),
                'amount' => $chargeback,
            ],
            'net_sales_amount' => $gross - $refund - $chargeback,
            'chargeback_reversals' => ['count' => (int) ($adjustments->reversal_count ?? 0)],
            'pending_adjustments' => ['count' => (int) ($adjustments->pending_count ?? 0)],
            'days' => $daily,
        ];
    }

    /** @return array<string, mixed> */
    public function dailySales(
        V2AdminAuthorizationContext $context,
        string $date,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $query = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.status', 'succeeded')
            ->where('payments.succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('payments.succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->orderBy('payments.id')
            ->select([
                'payments.id',
                'payments.public_id',
                'users.public_id as user_public_id',
                'payments.amount',
                'payments.currency',
                'payments.paid_point_amount',
                'payments.free_point_amount',
                'payments.succeeded_at',
            ]);
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'payment_id' => $row->public_id,
            'user_id' => $row->user_public_id,
            'amount' => (int) $row->amount,
            'currency' => $row->currency,
            'paid_points' => (int) $row->paid_point_amount,
            'free_points' => (int) $row->free_point_amount,
            'succeeded_at' => (string) $row->succeeded_at,
            'business_date' => $period->value,
        ]);
        $this->auditView($admin, $context, 'sales_daily', $period->value);

        return ['date' => $period->value, ...$items];
    }

    /** @return array<string, mixed> */
    public function adjustments(
        V2AdminAuthorizationContext $context,
        string $date,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $query = DB::table('payment_adjustments')
            ->join('payments', 'payments.id', '=', 'payment_adjustments.payment_id')
            ->where(
                'payment_adjustments.succeeded_at',
                '>=',
                $period->utcStart()->toIso8601String()
            )
            ->where(
                'payment_adjustments.succeeded_at',
                '<',
                $period->utcEnd()->toIso8601String()
            )
            ->orderBy('payment_adjustments.id')
            ->select([
                'payment_adjustments.id',
                'payment_adjustments.public_id',
                'payments.public_id as payment_public_id',
                'payment_adjustments.type',
                'payment_adjustments.status',
                'payment_adjustments.amount',
                'payment_adjustments.currency',
                'payment_adjustments.succeeded_at',
            ]);
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'adjustment_id' => $row->public_id,
            'payment_id' => $row->payment_public_id,
            'type' => $row->type,
            'status' => $row->status,
            'amount' => (int) $row->amount,
            'currency' => $row->currency,
            'succeeded_at' => (string) $row->succeeded_at,
            'business_date' => $period->value,
        ]);
        $this->auditView($admin, $context, 'adjustments_daily', $period->value);

        return ['date' => $period->value, ...$items];
    }

    /** @return array<string, mixed> */
    public function pointSummary(
        V2AdminAuthorizationContext $context,
        string $month
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $rows = DB::table('point_ledger_entries as ledger')
            ->join('point_operations as operation', 'operation.id', '=', 'ledger.point_operation_id')
            ->where('ledger.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('ledger.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->groupBy('ledger.point_type', 'ledger.entry_type', 'operation.source_type')
            ->orderBy('ledger.point_type')
            ->orderBy('ledger.entry_type')
            ->orderBy('operation.source_type')
            ->selectRaw(
                'ledger.point_type, ledger.entry_type, operation.source_type, COUNT(*) AS entry_count, SUM(ABS(ledger.amount_delta)) AS amount'
            )
            ->get()
            ->map(fn (object $row): array => [
                'point_type' => $row->point_type,
                'entry_type' => $row->entry_type,
                'source_type' => $row->source_type,
                'entry_count' => (int) $row->entry_count,
                'amount' => (int) $row->amount,
            ])->all();
        $this->auditView($admin, $context, 'points_monthly', $period->value);

        return ['month' => $period->value, 'entries' => $rows];
    }

    /** @return array<string, mixed> */
    public function dashboardMonthlySales(
        V2AdminAuthorizationContext $context,
        string $month
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $payments = DB::table('payments')
            ->where('status', 'succeeded')
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date AS report_date")
            ->selectRaw('COUNT(*) AS payment_count, SUM(amount) AS gross_sales_amount')
            ->groupByRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date")
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->report_date);
        $adjustments = DB::table('payment_adjustments')
            ->where('status', 'succeeded')
            ->whereIn('type', ['refund', 'chargeback'])
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date AS report_date")
            ->selectRaw("COUNT(*) FILTER (WHERE type = 'refund') AS refund_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'refund'), 0) AS refund_amount")
            ->selectRaw("COUNT(*) FILTER (WHERE type = 'chargeback') AS chargeback_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'chargeback'), 0) AS chargeback_amount")
            ->groupByRaw("(succeeded_at AT TIME ZONE 'Asia/Tokyo')::date")
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->report_date);
        $dates = $payments->keys()->merge($adjustments->keys())->unique()->sort()->values();
        $days = $dates->map(function (string $date) use ($payments, $adjustments): array {
            return [
                'date' => $date,
                'summary' => $this->dashboardSalesSummary(
                    $payments->get($date),
                    $adjustments->get($date)
                ),
            ];
        })->all();
        $this->auditView($admin, $context, 'dashboard_sales_monthly', $period->value, 'normal');

        return [
            'month' => $period->value,
            'timezone' => 'Asia/Tokyo',
            'currency' => 'JPY',
            'basis' => 'operational_event_aggregation_not_accounting_recognition',
            'summary' => $this->dashboardSalesSummaryForPeriod($period),
            'days' => $days,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardDailySales(
        V2AdminAuthorizationContext $context,
        string $date,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $query = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.status', 'succeeded')
            ->where('payments.succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('payments.succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->orderBy('payments.id')
            ->select([
                'payments.id',
                'payments.public_id',
                'users.public_id as user_public_id',
                'payments.amount',
                'payments.currency',
                'payments.plan_name_snapshot',
                'payments.provider_code',
                'payments.status',
                'payments.succeeded_at',
            ]);
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'payment_id' => $row->public_id,
            'user_id' => $row->user_public_id,
            'amount' => (int) $row->amount,
            'currency' => $row->currency,
            'plan_name' => $row->plan_name_snapshot,
            'provider' => $row->provider_code,
            'status' => $row->status,
            'succeeded_at' => (string) $row->succeeded_at,
        ]);
        $this->auditView($admin, $context, 'dashboard_sales_daily', $period->value, 'normal');

        return [
            'date' => $period->value,
            'timezone' => 'Asia/Tokyo',
            'currency' => 'JPY',
            'basis' => 'operational_event_aggregation_not_accounting_recognition',
            'summary' => $this->dashboardSalesSummaryForPeriod($period),
            ...$items,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardMonthlyPoints(
        V2AdminAuthorizationContext $context,
        string $month
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $rows = $this->dashboardPointSummaryQuery($period)
            ->selectRaw("(ledger.occurred_at AT TIME ZONE 'Asia/Tokyo')::date AS report_date")
            ->groupByRaw("(ledger.occurred_at AT TIME ZONE 'Asia/Tokyo')::date")
            ->orderBy('report_date')
            ->get();
        $days = $rows->map(fn (object $row): array => [
            'date' => (string) $row->report_date,
            'summary' => $this->dashboardPointSummary($row),
        ])->all();
        $summary = $this->dashboardPointSummaryQuery($period)->first();
        $this->auditView($admin, $context, 'dashboard_points_monthly', $period->value, 'normal');

        return [
            'month' => $period->value,
            'timezone' => 'Asia/Tokyo',
            'qa_excluded' => true,
            'summary' => $this->dashboardPointSummary($summary),
            'days' => $days,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardDailyPoints(
        V2AdminAuthorizationContext $context,
        string $date,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $summary = $this->dashboardPointSummaryQuery($period)->first();
        $query = DB::table('point_operations as operation')
            ->join('point_ledger_entries as ledger', 'ledger.point_operation_id', '=', 'operation.id')
            ->join('users', 'users.id', '=', 'operation.user_id')
            ->leftJoin('draw_requests as request', function ($join): void {
                $join->on('request.id', '=', 'operation.source_id')
                    ->where('operation.source_type', '=', 'draw');
            })
            ->leftJoin('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->where('operation.is_qa', false)
            ->where('ledger.entry_type', 'spend')
            ->where('ledger.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('ledger.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->groupBy([
                'operation.id',
                'operation.public_id',
                'operation.source_type',
                'operation.occurred_at',
                'users.public_id',
                'request.public_id',
                'request.executed_count',
                'version.public_id',
                'version.title',
            ])
            ->orderBy('operation.id')
            ->select([
                'operation.id',
                'operation.public_id',
                'operation.source_type',
                'operation.occurred_at',
                'users.public_id as user_public_id',
                'request.public_id as draw_request_public_id',
                'request.executed_count',
                'version.public_id as gacha_version_public_id',
                'version.title as gacha_title',
            ])
            ->selectRaw("COALESCE(SUM(ABS(ledger.amount_delta)) FILTER (WHERE ledger.point_type = 'paid'), 0) AS paid_consumed")
            ->selectRaw("COALESCE(SUM(ABS(ledger.amount_delta)) FILTER (WHERE ledger.point_type = 'free'), 0) AS free_consumed");
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'operation_id' => $row->public_id,
            'user_id' => $row->user_public_id,
            'source_type' => $row->source_type,
            'draw_request_id' => $row->draw_request_public_id,
            'gacha_version_id' => $row->gacha_version_public_id,
            'gacha_title' => $row->gacha_title,
            'draw_count' => $row->executed_count === null ? null : (int) $row->executed_count,
            'paid_consumed' => (int) $row->paid_consumed,
            'free_consumed' => (int) $row->free_consumed,
            'occurred_at' => (string) $row->occurred_at,
        ]);
        $this->auditView($admin, $context, 'dashboard_points_daily', $period->value, 'normal');

        return [
            'date' => $period->value,
            'timezone' => 'Asia/Tokyo',
            'qa_excluded' => true,
            'summary' => $this->dashboardPointSummary($summary),
            ...$items,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardReversals(
        V2AdminAuthorizationContext $context,
        string $startDate,
        string $endDate,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::dateRange($startDate, $endDate);
        $occurredAt = 'COALESCE(payment_adjustments.succeeded_at, payment_adjustments.requested_at)';
        $query = DB::table('payment_adjustments')
            ->join('payments', 'payments.id', '=', 'payment_adjustments.payment_id')
            ->whereRaw($occurredAt.' >= ?', [$period->utcStart()->toIso8601String()])
            ->whereRaw($occurredAt.' < ?', [$period->utcEnd()->toIso8601String()])
            ->orderBy('payment_adjustments.id')
            ->select([
                'payment_adjustments.id',
                'payment_adjustments.public_id',
                'payments.public_id as payment_public_id',
                'payment_adjustments.type',
                'payment_adjustments.status',
                'payment_adjustments.amount',
                'payment_adjustments.currency',
                'payment_adjustments.succeeded_at',
            ])
            ->selectRaw($occurredAt.' AS occurred_at');
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'adjustment_id' => $row->public_id,
            'payment_id' => $row->payment_public_id,
            'type' => $row->type,
            'status' => $row->status,
            'amount' => (int) $row->amount,
            'currency' => $row->currency,
            'occurred_at' => (string) $row->occurred_at,
            'succeeded_at' => $row->succeeded_at === null ? null : (string) $row->succeeded_at,
        ]);
        $this->auditView($admin, $context, 'dashboard_reversals', $period->value, 'normal');

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'timezone' => 'Asia/Tokyo',
            ...$items,
        ];
    }

    /** @return array<string, mixed> */
    public function gachaSummary(
        V2AdminAuthorizationContext $context,
        string $month,
        string $qaFilter
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $this->assertQaFilter($qaFilter);
        $query = DB::table('draw_requests as request')
            ->join('gacha_draw_states as state', 'state.id', '=', 'request.gacha_draw_state_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'state.gacha_id')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->where('request.status', 'completed')
            ->where('request.completed_at', '>=', $period->utcStart()->toIso8601String())
            ->where('request.completed_at', '<', $period->utcEnd()->toIso8601String())
            ->groupBy('gacha.public_id', 'version.public_id', 'version.title')
            ->orderBy('gacha.public_id')
            ->selectRaw(
                'gacha.public_id AS gacha_public_id, version.public_id AS version_public_id, version.title, COUNT(*) AS request_count, SUM(request.executed_count) AS draw_count, SUM(request.consumed_paid_points) AS paid_points, SUM(request.consumed_free_points) AS free_points'
            );
        $this->applyQaFilter($query, 'request.is_qa_draw', $qaFilter);
        $items = $query->get()->map(fn (object $row): array => [
            'gacha_id' => $row->gacha_public_id,
            'gacha_version_id' => $row->version_public_id,
            'title' => $row->title,
            'request_count' => (int) $row->request_count,
            'draw_count' => (int) $row->draw_count,
            'consumed_paid_points' => (int) $row->paid_points,
            'consumed_free_points' => (int) $row->free_points,
        ])->all();
        $rankResults = DB::table('draw_results as result')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->join('gacha_draw_states as state', 'state.id', '=', 'request.gacha_draw_state_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'state.gacha_id')
            ->leftJoin('catalog_ranks as rank', 'rank.id', '=', 'result.rank_id')
            ->whereNotNull('result.rank_id')
            ->where('result.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('result.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->groupBy('gacha.public_id', 'rank.public_id', 'rank.code', 'rank.display_name')
            ->orderBy('gacha.public_id')
            ->orderBy('rank.code')
            ->selectRaw(
                'gacha.public_id AS gacha_public_id, rank.public_id AS rank_public_id, rank.code AS rank_code, rank.display_name AS rank_name, COUNT(*) AS result_count'
            );
        $this->applyQaFilter($rankResults, 'request.is_qa_draw', $qaFilter);
        $prizeResults = DB::table('draw_results as result')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->join('gacha_draw_states as state', 'state.id', '=', 'request.gacha_draw_state_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'state.gacha_id')
            ->leftJoin(
                'catalog_gacha_version_prizes as version_prize',
                'version_prize.id',
                '=',
                'result.gacha_version_prize_id'
            )
            ->leftJoin('catalog_prizes as prize', 'prize.id', '=', 'version_prize.prize_id')
            ->where('result.result_type', 'prize')
            ->where('result.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('result.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->groupBy('gacha.public_id', 'prize.public_id', 'prize.display_name')
            ->orderBy('gacha.public_id')
            ->orderBy('prize.public_id')
            ->selectRaw(
                'gacha.public_id AS gacha_public_id, prize.public_id AS prize_public_id, prize.display_name AS prize_name, COUNT(*) AS result_count'
            );
        $this->applyQaFilter($prizeResults, 'request.is_qa_draw', $qaFilter);
        $this->auditView($admin, $context, 'gachas_monthly', $period->value, $qaFilter);

        return [
            'month' => $period->value,
            'qa_filter' => $qaFilter,
            'items' => $items,
            'rank_results' => $rankResults->get()->map(fn (object $row): array => [
                'gacha_id' => $row->gacha_public_id,
                'rank_id' => $row->rank_public_id,
                'rank_code' => $row->rank_code,
                'rank_name' => $row->rank_name,
                'result_count' => (int) $row->result_count,
            ])->all(),
            'prize_results' => $prizeResults->get()->map(fn (object $row): array => [
                'gacha_id' => $row->gacha_public_id,
                'prize_id' => $row->prize_public_id,
                'prize_name' => $row->prize_name,
                'result_count' => (int) $row->result_count,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function drawHistory(
        V2AdminAuthorizationContext $context,
        string $date,
        string $qaFilter,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $this->assertQaFilter($qaFilter);
        $query = DB::table('draw_requests as request')
            ->join('users', 'users.id', '=', 'request.user_id')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->join('catalog_probability_versions as probability', 'probability.id', '=', 'request.probability_version_id')
            ->where('request.status', 'completed')
            ->where('request.completed_at', '>=', $period->utcStart()->toIso8601String())
            ->where('request.completed_at', '<', $period->utcEnd()->toIso8601String())
            ->orderBy('request.id')
            ->select([
                'request.id',
                'request.public_id',
                'request.request_id',
                'request.requested_count',
                'request.executed_count',
                'request.consumed_paid_points',
                'request.consumed_free_points',
                'request.point_back_total',
                'request.is_qa_draw',
                'request.completed_at',
                'users.public_id as user_public_id',
                'version.public_id as gacha_version_public_id',
                'probability.public_id as probability_version_public_id',
            ]);
        $this->applyQaFilter($query, 'request.is_qa_draw', $qaFilter);
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'draw_request_id' => $row->public_id,
            'request_id' => $row->request_id,
            'user_id' => $row->user_public_id,
            'gacha_version_id' => $row->gacha_version_public_id,
            'probability_version_id' => $row->probability_version_public_id,
            'requested_count' => (int) $row->requested_count,
            'executed_count' => (int) $row->executed_count,
            'consumed_paid_points' => (int) $row->consumed_paid_points,
            'consumed_free_points' => (int) $row->consumed_free_points,
            'point_back_total' => (int) $row->point_back_total,
            'is_qa_draw' => (bool) $row->is_qa_draw,
            'completed_at' => (string) $row->completed_at,
        ]);
        $this->auditView($admin, $context, 'draw_history', $period->value, $qaFilter);

        return ['date' => $period->value, 'qa_filter' => $qaFilter, ...$items];
    }

    /** @return array<string, mixed> */
    public function drawResultHistory(
        V2AdminAuthorizationContext $context,
        string $date,
        string $qaFilter,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $this->assertQaFilter($qaFilter);
        $query = DB::table('draw_results as result')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->join('users', 'users.id', '=', 'result.user_id')
            ->leftJoin('catalog_ranks as rank', 'rank.id', '=', 'result.rank_id')
            ->leftJoin(
                'catalog_gacha_version_prizes as version_prize',
                'version_prize.id',
                '=',
                'result.gacha_version_prize_id'
            )
            ->leftJoin('catalog_prizes as prize', 'prize.id', '=', 'version_prize.prize_id')
            ->where('result.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('result.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->orderBy('result.id')
            ->select([
                'result.id',
                'result.public_id',
                'request.public_id as request_public_id',
                'users.public_id as user_public_id',
                'result.request_sequence',
                'result.draw_sequence_number',
                'result.result_type',
                'rank.public_id as rank_public_id',
                'rank.display_name as rank_name',
                'prize.public_id as prize_public_id',
                'prize.display_name as prize_name',
                'result.consumed_points',
                'result.point_back_amount',
                'result.is_qa_draw',
                'result.occurred_at',
            ]);
        $this->applyQaFilter($query, 'result.is_qa_draw', $qaFilter);
        $items = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'draw_result_id' => $row->public_id,
            'draw_request_id' => $row->request_public_id,
            'user_id' => $row->user_public_id,
            'request_sequence' => (int) $row->request_sequence,
            'draw_sequence_number' => (int) $row->draw_sequence_number,
            'result_type' => $row->result_type,
            'rank_id' => $row->rank_public_id,
            'rank_name' => $row->rank_name,
            'prize_id' => $row->prize_public_id,
            'prize_name' => $row->prize_name,
            'consumed_points' => (int) $row->consumed_points,
            'point_back_amount' => (int) $row->point_back_amount,
            'is_qa_draw' => (bool) $row->is_qa_draw,
            'occurred_at' => (string) $row->occurred_at,
        ]);
        $this->auditView($admin, $context, 'draw_result_history', $period->value, $qaFilter);

        return ['date' => $period->value, 'qa_filter' => $qaFilter, ...$items];
    }

    /** @return array<string, mixed> */
    public function snapshots(
        V2AdminAuthorizationContext $context,
        string $month,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::month($month);
        $query = DB::table('point_balance_snapshots')
            ->where('snapshot_date', '>=', $period->start->toDateString())
            ->where('snapshot_date', '<', $period->end->toDateString())
            ->orderBy('id')
            ->select([
                'id',
                'snapshot_date',
                'source_cutoff_at',
                'closing_paid_balance',
                'closing_free_balance',
                'paid_reserved_balance',
                'free_reserved_balance',
                'user_count',
                'open_lot_count',
                'is_base_date',
                'generation_run_id',
                'checksum',
                'generated_at',
            ]);
        $items = $this->page($query, $cursor, $limit, $this->snapshotResource(...));
        $this->auditView($admin, $context, 'point_snapshots', $period->value);

        return ['month' => $period->value, ...$items];
    }

    /** @return array<string, mixed> */
    public function snapshot(
        V2AdminAuthorizationContext $context,
        string $date
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $period = V2ReportingPeriod::date($date);
        $row = DB::table('point_balance_snapshots')
            ->where('snapshot_date', $period->value)
            ->first();
        if ($row === null) {
            throw new V2ReportingException(
                'REPORTING_RESOURCE_NOT_FOUND',
                404,
                'The Reporting resource was not found.'
            );
        }
        $this->auditView($admin, $context, 'point_snapshot', $period->value);

        return $this->snapshotResource($row);
    }

    /**
     * @param callable(object): array<string, mixed> $resource
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function page(
        Builder $query,
        ?string $cursor,
        int $limit,
        callable $resource
    ): array {
        $maximum = (int) config('v2_reporting.pagination.maximum', 100);
        if ($limit < 1 || $limit > $maximum) {
            throw new V2ReportingException(
                'REPORTING_LIMIT_INVALID',
                422,
                'The Reporting page limit is invalid.'
            );
        }
        $after = $this->cursor->decode($cursor);
        if ($after !== null) {
            $query->where($this->idColumn($query), '>', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map($resource)->values()->all(),
            'next_cursor' => $hasMore && $rows->isNotEmpty()
                ? $this->cursor->encode((int) $rows->last()->id)
                : null,
        ];
    }

    private function idColumn(Builder $query): string
    {
        foreach ($query->columns ?? [] as $column) {
            if (is_string($column) && str_ends_with($column, '.id')) {
                return $column;
            }
        }

        return 'id';
    }

    /** @return array<string, int> */
    private function dashboardSalesSummaryForPeriod(V2ReportingPeriod $period): array
    {
        $payment = DB::table('payments')
            ->where('status', 'succeeded')
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw('COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS gross_sales_amount')
            ->first();
        $adjustment = DB::table('payment_adjustments')
            ->where('status', 'succeeded')
            ->whereIn('type', ['refund', 'chargeback'])
            ->where('succeeded_at', '>=', $period->utcStart()->toIso8601String())
            ->where('succeeded_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw("COUNT(*) FILTER (WHERE type = 'refund') AS refund_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'refund'), 0) AS refund_amount")
            ->selectRaw("COUNT(*) FILTER (WHERE type = 'chargeback') AS chargeback_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE type = 'chargeback'), 0) AS chargeback_amount")
            ->first();

        return $this->dashboardSalesSummary($payment, $adjustment);
    }

    /** @return array<string, int> */
    private function dashboardSalesSummary(?object $payment, ?object $adjustment): array
    {
        $gross = (int) ($payment->gross_sales_amount ?? 0);
        $refund = (int) ($adjustment->refund_amount ?? 0);
        $chargeback = (int) ($adjustment->chargeback_amount ?? 0);

        return [
            'payment_count' => (int) ($payment->payment_count ?? 0),
            'gross_sales_amount' => $gross,
            'refund_count' => (int) ($adjustment->refund_count ?? 0),
            'refund_amount' => $refund,
            'chargeback_count' => (int) ($adjustment->chargeback_count ?? 0),
            'chargeback_amount' => $chargeback,
            'net_sales_amount' => $gross - $refund - $chargeback,
        ];
    }

    private function dashboardPointSummaryQuery(V2ReportingPeriod $period): Builder
    {
        return DB::table('point_ledger_entries as ledger')
            ->join('point_operations as operation', 'operation.id', '=', 'ledger.point_operation_id')
            ->where('operation.is_qa', false)
            ->where('ledger.entry_type', 'spend')
            ->where('ledger.occurred_at', '>=', $period->utcStart()->toIso8601String())
            ->where('ledger.occurred_at', '<', $period->utcEnd()->toIso8601String())
            ->selectRaw("COALESCE(SUM(ABS(ledger.amount_delta)) FILTER (WHERE ledger.point_type = 'paid'), 0) AS paid_consumed")
            ->selectRaw("COALESCE(SUM(ABS(ledger.amount_delta)) FILTER (WHERE ledger.point_type = 'free'), 0) AS free_consumed");
    }

    /** @return array{paid_consumed: int, free_consumed: int} */
    private function dashboardPointSummary(?object $row): array
    {
        return [
            'paid_consumed' => (int) ($row->paid_consumed ?? 0),
            'free_consumed' => (int) ($row->free_consumed ?? 0),
        ];
    }

    private function assertQaFilter(string $qaFilter): void
    {
        if (! in_array($qaFilter, ['all', 'normal', 'qa'], true)) {
            throw new V2ReportingException(
                'REPORTING_FILTER_INVALID',
                422,
                'The Reporting filter is invalid.'
            );
        }
    }

    private function applyQaFilter(
        Builder $query,
        string $column,
        string $qaFilter
    ): void {
        if ($qaFilter === 'normal') {
            $query->where($column, false);
        } elseif ($qaFilter === 'qa') {
            $query->where($column, true);
        }
    }

    /** @return array<string, mixed> */
    private function snapshotResource(object $row): array
    {
        return [
            'snapshot_date' => (string) $row->snapshot_date,
            'source_cutoff_at' => (string) $row->source_cutoff_at,
            'closing_paid_balance' => (int) $row->closing_paid_balance,
            'closing_free_balance' => (int) $row->closing_free_balance,
            'paid_reserved_balance' => (int) $row->paid_reserved_balance,
            'free_reserved_balance' => (int) $row->free_reserved_balance,
            'user_count' => (int) $row->user_count,
            'open_lot_count' => (int) $row->open_lot_count,
            'is_base_date' => (bool) $row->is_base_date,
            'generation_run_id' => $row->generation_run_id,
            'checksum' => $row->checksum,
            'generated_at' => (string) $row->generated_at,
        ];
    }

    private function auditView(
        Admin $admin,
        V2AdminAuthorizationContext $context,
        string $reportType,
        string $period,
        string $qaFilter = 'all'
    ): void {
        $this->audit->record('report.viewed', [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'outcome' => 'success',
            'metadata' => [
                'report_type' => $reportType,
                'period' => $period,
                'qa_filter' => $qaFilter,
            ],
        ]);
    }
}
