<?php

namespace App\Domain\Admin\Services;

use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\DrawRequest;
use App\Models\Payment;
use App\Models\PointPurchasePlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesManagementReportService
{
    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * @return array{year: int, month: int, timezone: string, total_amount: int, refund_amount: int, chargeback_amount: int, net_amount: int, days: array<int, array<string, mixed>>}
     */
    public function monthlySales(int $year, int $month): array
    {
        [$start, $end] = $this->monthRange($year, $month);
        $days = $this->emptyCalendarDays($start, $end);

        $grossPayments = Payment::query()
            ->whereIn('status', [
                PaymentStatus::Succeeded->value,
                PaymentStatus::Refunded->value,
                PaymentStatus::Chargeback->value,
            ])
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->get(['id', 'provider', 'status', 'amount', 'metadata', 'paid_at']);

        foreach ($grossPayments as $payment) {
            $date = $this->dateKey($payment->paid_at);
            $method = $this->paymentMethod($payment);

            $days[$date]['total_amount'] += (int) $payment->amount;
            $days[$date]['payment_count']++;
            $days[$date]['status_counts'][$payment->status?->value ?? (string) $payment->status] =
                ($days[$date]['status_counts'][$payment->status?->value ?? (string) $payment->status] ?? 0) + 1;

            if (! isset($days[$date]['methods'][$method])) {
                $days[$date]['methods'][$method] = [
                    'payment_method' => $method,
                    'amount' => 0,
                    'count' => 0,
                ];
            }

            $days[$date]['methods'][$method]['amount'] += (int) $payment->amount;
            $days[$date]['methods'][$method]['count']++;
        }

        $this->applyPaymentEventAmounts($days, 'refunded_at', 'refund_amount', 'refund_count', $start, $end);
        $this->applyPaymentEventAmounts($days, 'chargeback_at', 'chargeback_amount', 'chargeback_count', $start, $end);

        $totalAmount = 0;
        $refundAmount = 0;
        $chargebackAmount = 0;

        foreach ($days as &$day) {
            $day['methods'] = array_values($day['methods']);
            $day['net_amount'] = $day['total_amount'] - $day['refund_amount'] - $day['chargeback_amount'];
            $totalAmount += $day['total_amount'];
            $refundAmount += $day['refund_amount'];
            $chargebackAmount += $day['chargeback_amount'];
        }
        unset($day);

        return [
            'year' => $year,
            'month' => $month,
            'timezone' => self::TIMEZONE,
            'total_amount' => $totalAmount,
            'refund_amount' => $refundAmount,
            'chargeback_amount' => $chargebackAmount,
            'net_amount' => $totalAmount - $refundAmount - $chargebackAmount,
            'days' => array_values($days),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function dailyPayments(string $date, int $perPage = 20): array
    {
        [$start, $end] = $this->dayRange($date);

        $paginator = Payment::query()
            ->with('user')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $plans = $this->plansForPayments(collect($paginator->items()));

        return [
            'data' => collect($paginator->items())
                ->map(fn (Payment $payment): array => $this->paymentRow($payment, $plans))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function dailyAdjustments(string $date): array
    {
        [$start, $end] = $this->dayRange($date);

        $refunds = Payment::query()
            ->with('user')
            ->where('status', PaymentStatus::Refunded->value)
            ->whereNotNull('refunded_at')
            ->where('refunded_at', '>=', $start)
            ->where('refunded_at', '<', $end)
            ->get();

        $chargebacks = Payment::query()
            ->with('user')
            ->where('status', PaymentStatus::Chargeback->value)
            ->whereNotNull('chargeback_at')
            ->where('chargeback_at', '>=', $start)
            ->where('chargeback_at', '<', $end)
            ->get();

        $payments = $refunds
            ->concat($chargebacks)
            ->sortByDesc(fn (Payment $payment): string => ($payment->chargeback_at ?? $payment->refunded_at)?->toIso8601String() ?? '')
            ->values();

        $plans = $this->plansForPayments($payments);
        $refundAmount = $refunds->sum(fn (Payment $payment): int => (int) $payment->amount);
        $chargebackAmount = $chargebacks->sum(fn (Payment $payment): int => (int) $payment->amount);
        $totalAmount = Payment::query()
            ->whereIn('status', [
                PaymentStatus::Succeeded->value,
                PaymentStatus::Refunded->value,
                PaymentStatus::Chargeback->value,
            ])
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->sum('amount');

        return [
            'data' => $payments
                ->map(fn (Payment $payment): array => $this->paymentAdjustmentRow($payment, $plans))
                ->values()
                ->all(),
            'summary' => [
                'date' => $date,
                'timezone' => self::TIMEZONE,
                'total_amount' => (int) $totalAmount,
                'refund_amount' => (int) $refundAmount,
                'chargeback_amount' => (int) $chargebackAmount,
                'net_amount' => (int) $totalAmount - (int) $refundAmount - (int) $chargebackAmount,
            ],
        ];
    }

    /**
     * @return array{year: int, month: int, timezone: string, paid_point_total: int, free_point_total: int, days: array<int, array<string, mixed>>}
     */
    public function monthlyPointConsumption(int $year, int $month): array
    {
        [$start, $end] = $this->monthRange($year, $month);
        $days = $this->emptyPointCalendarDays($start, $end);
        $rows = $this->pointConsumptionBaseQuery($start, $end)->get();

        foreach ($rows as $row) {
            $date = $this->dateKey($row->spent_at);
            $gachaId = (int) $row->gacha_id;

            $days[$date]['paid_point_total'] += (int) $row->paid_point;
            $days[$date]['free_point_total'] += (int) $row->free_point;

            if (! isset($days[$date]['gachas'][$gachaId])) {
                $days[$date]['gachas'][$gachaId] = [
                    'gacha_id' => $gachaId,
                    'gacha_title' => $row->gacha_title,
                    'paid_point' => 0,
                    'free_point' => 0,
                    'draw_count' => 0,
                ];
            }

            $days[$date]['gachas'][$gachaId]['paid_point'] += (int) $row->paid_point;
            $days[$date]['gachas'][$gachaId]['free_point'] += (int) $row->free_point;
            $days[$date]['gachas'][$gachaId]['draw_count'] += (int) $row->draw_count;
        }

        $paidTotal = 0;
        $freeTotal = 0;

        foreach ($days as &$day) {
            $day['gachas'] = array_values($day['gachas']);
            $paidTotal += $day['paid_point_total'];
            $freeTotal += $day['free_point_total'];
        }
        unset($day);

        return [
            'year' => $year,
            'month' => $month,
            'timezone' => self::TIMEZONE,
            'paid_point_total' => $paidTotal,
            'free_point_total' => $freeTotal,
            'days' => array_values($days),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function dailyPointConsumption(string $date, int $perPage = 20): array
    {
        [$start, $end] = $this->dayRange($date);

        $paginator = $this->pointConsumptionBaseQuery($start, $end)
            ->orderByRaw('MAX(point_ledgers.created_at) DESC')
            ->orderByDesc('draw_requests.id')
            ->paginate($perPage);

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $row): array => $this->pointConsumptionRow($row))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function drawRequestDetail(DrawRequest $drawRequest): array
    {
        $drawRequest->load([
            'user',
            'gacha',
            'results.rank',
            'results.prize',
        ]);

        return [
            'id' => $drawRequest->id,
            'user' => $drawRequest->user ? [
                'id' => $drawRequest->user->id,
                'name' => $drawRequest->user->name,
                'email' => $drawRequest->user->email,
            ] : null,
            'gacha' => $drawRequest->gacha ? [
                'id' => $drawRequest->gacha->id,
                'title' => $drawRequest->gacha->title,
                'slug' => $drawRequest->gacha->slug,
                'price' => $drawRequest->gacha->price,
            ] : null,
            'draw_count' => $drawRequest->draw_count,
            'status' => $drawRequest->status?->value ?? $drawRequest->status,
            'consumed_point_total' => $drawRequest->consumed_point_total,
            'created_at' => $drawRequest->created_at?->toIso8601String(),
            'results' => $drawRequest->results
                ->sortBy('draw_sequence_number')
                ->map(fn ($result): array => [
                    'id' => $result->id,
                    'draw_sequence_number' => $result->draw_sequence_number,
                    'result_type' => $result->result_type?->value ?? $result->result_type,
                    'rank' => $result->rank ? [
                        'id' => $result->rank->id,
                        'rank_key' => $result->rank->rank_key,
                        'display_name' => $result->rank->display_name,
                    ] : null,
                    'prize' => $result->prize ? [
                        'id' => $result->prize->id,
                        'name' => $result->prize->name,
                        'image_url' => $result->prize->image_url,
                    ] : null,
                    'consumed_point' => $result->consumed_point,
                    'granted_point' => $result->granted_point,
                    'created_at' => $result->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function monthRange(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE)->startOfDay();

        return [$start, $start->addMonth()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayRange(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, self::TIMEZONE)->startOfDay();

        return [$start, $start->addDay()];
    }

    private function paymentMethod(Payment $payment): string
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $method = $metadata['payment_method'] ?? null;

        return is_string($method) && $method !== '' ? $method : (string) $payment->provider;
    }

    /**
     * @param array<string, array<string, mixed>> $days
     */
    private function applyPaymentEventAmounts(
        array &$days,
        string $timestampColumn,
        string $amountKey,
        string $countKey,
        CarbonInterface $start,
        CarbonInterface $end,
    ): void {
        $status = $timestampColumn === 'chargeback_at'
            ? PaymentStatus::Chargeback->value
            : PaymentStatus::Refunded->value;

        $payments = Payment::query()
            ->where('status', $status)
            ->whereNotNull($timestampColumn)
            ->where($timestampColumn, '>=', $start)
            ->where($timestampColumn, '<', $end)
            ->get(['id', 'amount', $timestampColumn]);

        foreach ($payments as $payment) {
            $date = $this->dateKey($payment->{$timestampColumn});
            $days[$date][$amountKey] += (int) $payment->amount;
            $days[$date][$countKey]++;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyCalendarDays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];

        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $days[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'total_amount' => 0,
                'refund_amount' => 0,
                'chargeback_amount' => 0,
                'net_amount' => 0,
                'payment_count' => 0,
                'refund_count' => 0,
                'chargeback_count' => 0,
                'status_counts' => [],
                'methods' => [],
            ];
        }

        return $days;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyPointCalendarDays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];

        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $days[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'paid_point_total' => 0,
                'free_point_total' => 0,
                'gachas' => [],
            ];
        }

        return $days;
    }

    /**
     * @param Collection<int, Payment> $payments
     * @return Collection<int, PointPurchasePlan>
     */
    private function plansForPayments(Collection $payments): Collection
    {
        $planIds = $payments
            ->map(fn (Payment $payment): ?int => $this->paymentPlanId($payment))
            ->filter()
            ->unique()
            ->values();

        if ($planIds->isEmpty()) {
            return collect();
        }

        return PointPurchasePlan::query()
            ->whereIn('id', $planIds)
            ->get()
            ->keyBy('id');
    }

    private function paymentPlanId(Payment $payment): ?int
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $planId = $metadata['point_purchase_plan_id'] ?? null;

        return is_numeric($planId) ? (int) $planId : null;
    }

    /**
     * @param Collection<int, PointPurchasePlan> $plans
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment, Collection $plans): array
    {
        $planId = $this->paymentPlanId($payment);
        $plan = $planId ? $plans->get($planId) : null;

        return [
            'id' => $payment->id,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payment_method' => $this->paymentMethod($payment),
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'chargeback_at' => $payment->chargeback_at?->toIso8601String(),
            'purchase_plan' => $planId ? [
                'id' => $planId,
                'name' => $plan?->name ?? '削除済みプラン',
                'deleted' => $plan === null,
            ] : null,
            'amount' => $payment->amount,
            'status' => $payment->status?->value ?? $payment->status,
            'user' => $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
                'email' => $payment->user->email,
            ] : null,
        ];
    }

    /**
     * @param Collection<int, PointPurchasePlan> $plans
     * @return array<string, mixed>
     */
    private function paymentAdjustmentRow(Payment $payment, Collection $plans): array
    {
        $type = $payment->status === PaymentStatus::Chargeback ? 'chargeback' : 'refund';
        $occurredAt = $type === 'chargeback' ? $payment->chargeback_at : $payment->refunded_at;
        $planId = $this->paymentPlanId($payment);
        $plan = $planId ? $plans->get($planId) : null;

        return [
            'type' => $type,
            'occurred_at' => $occurredAt?->toIso8601String(),
            'amount' => (int) $payment->amount,
            'payment_id' => $payment->id,
            'original_paid_at' => $payment->paid_at?->toIso8601String(),
            'user' => $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
                'email' => $payment->user->email,
            ] : null,
            'purchase_plan' => $planId ? [
                'id' => $planId,
                'name' => $plan?->name ?? '削除済みプラン',
                'deleted' => $plan === null,
            ] : null,
            'provider' => $payment->provider,
            'payment_method' => $this->paymentMethod($payment),
            'status' => $payment->status?->value ?? $payment->status,
        ];
    }

    private function pointConsumptionBaseQuery(CarbonInterface $start, CarbonInterface $end)
    {
        return DB::table('point_ledgers')
            ->join('draw_requests', 'draw_requests.id', '=', 'point_ledgers.related_id')
            ->join('users', 'users.id', '=', 'draw_requests.user_id')
            ->join('gachas', 'gachas.id', '=', 'draw_requests.gacha_id')
            ->where('point_ledgers.ledger_type', PointLedgerType::Spend->value)
            ->where('point_ledgers.related_type', 'draw_request')
            ->where('point_ledgers.amount', '<', 0)
            ->where('point_ledgers.created_at', '>=', $start)
            ->where('point_ledgers.created_at', '<', $end)
            ->groupBy(
                'draw_requests.id',
                'draw_requests.user_id',
                'draw_requests.gacha_id',
                'draw_requests.draw_count',
                'draw_requests.status',
                'draw_requests.created_at',
                'users.name',
                'users.email',
                'gachas.title',
            )
            ->select([
                'draw_requests.id as draw_request_id',
                'draw_requests.user_id',
                'draw_requests.gacha_id',
                'draw_requests.draw_count',
                'draw_requests.status',
                'draw_requests.created_at as draw_created_at',
                'users.name as user_name',
                'users.email as user_email',
                'gachas.title as gacha_title',
            ])
            ->selectRaw('MAX(point_ledgers.created_at) as spent_at')
            ->selectRaw("COALESCE(SUM(CASE WHEN point_ledgers.point_type = ? THEN ABS(point_ledgers.amount) ELSE 0 END), 0) as paid_point", [PointType::Paid->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN point_ledgers.point_type = ? THEN ABS(point_ledgers.amount) ELSE 0 END), 0) as free_point", [PointType::Free->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pointConsumptionRow(object $row): array
    {
        return [
            'draw_request_id' => (int) $row->draw_request_id,
            'datetime' => CarbonImmutable::parse($row->spent_at, self::TIMEZONE)->toIso8601String(),
            'paid_point' => (int) $row->paid_point,
            'free_point' => (int) $row->free_point,
            'user' => [
                'id' => (int) $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
            ],
            'gacha' => [
                'id' => (int) $row->gacha_id,
                'title' => $row->gacha_title,
            ],
            'draw_count' => (int) $row->draw_count,
            'status' => $row->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function dateKey(mixed $value): string
    {
        return CarbonImmutable::parse($value, self::TIMEZONE)->toDateString();
    }
}
