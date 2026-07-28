<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\ValueObjects\V2ExportDefinition;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2ExportRowSource
{
    /** @return list<string> */
    public function headers(V2ExportDefinition $definition): array
    {
        return match ($definition->reportType) {
            'sales' => [
                'business_date',
                'payment_id',
                'user_id',
                'amount',
                'currency',
                'paid_points',
                'free_points',
                'succeeded_at',
            ],
            'adjustments' => [
                'business_date',
                'adjustment_id',
                'payment_id',
                'type',
                'status',
                'amount',
                'currency',
                'succeeded_at',
            ],
            'point_ledger' => [
                'business_date',
                'operation_id',
                'user_id',
                'point_type',
                'entry_type',
                'source_type',
                'amount_delta',
                'occurred_at',
            ],
            'draw_results' => [
                'business_date',
                'draw_request_id',
                'draw_result_id',
                'user_id',
                'gacha_version_id',
                'request_sequence',
                'result_type',
                'rank_id',
                'prize_id',
                'consumed_points',
                'point_back_amount',
                'is_qa_draw',
                'occurred_at',
            ],
            'point_snapshots' => [
                'business_date',
                'source_cutoff_at',
                'closing_paid_balance',
                'closing_free_balance',
                'paid_reserved_balance',
                'free_reserved_balance',
                'user_count',
                'open_lot_count',
                'is_base_date',
                'checksum',
                'generated_at',
            ],
        };
    }

    public function count(
        V2ExportDefinition $definition,
        CarbonImmutable $cutoff
    ): int {
        return (int) $this->query($definition, $cutoff)->count();
    }

    /** @return Generator<int, array<string, scalar|null>> */
    public function rows(
        V2ExportDefinition $definition,
        CarbonImmutable $cutoff
    ): Generator {
        $lastId = 0;
        do {
            $rows = $this->query($definition, $cutoff)
                ->where($this->idColumn($definition), '>', $lastId)
                ->orderBy($this->idColumn($definition))
                ->limit(500)
                ->get();
            foreach ($rows as $row) {
                $lastId = (int) $row->source_id;
                yield $this->resource($definition, $row);
            }
        } while ($rows->count() === 500);
    }

    private function query(
        V2ExportDefinition $definition,
        CarbonImmutable $cutoff
    ): Builder {
        $query = match ($definition->reportType) {
            'sales' => $this->sales(),
            'adjustments' => $this->adjustments(),
            'point_ledger' => $this->pointLedger(),
            'draw_results' => $this->drawResults(),
            'point_snapshots' => $this->snapshots(),
        };
        [$timestamp, $businessDate] = match ($definition->reportType) {
            'sales' => ['payments.succeeded_at', null],
            'adjustments' => ['payment_adjustments.succeeded_at', null],
            'point_ledger' => ['ledger.occurred_at', null],
            'draw_results' => ['result.occurred_at', null],
            'point_snapshots' => [null, 'point_balance_snapshots.snapshot_date'],
        };
        if ($timestamp !== null) {
            $query
                ->where(
                    $timestamp,
                    '>=',
                    $definition->period->utcStart()->toIso8601String()
                )
                ->where(
                    $timestamp,
                    '<',
                    $definition->period->utcEnd()->toIso8601String()
                )
                ->where($timestamp, '<=', $cutoff->toIso8601String());
        } else {
            $query
                ->where($businessDate, '>=', $definition->period->start->toDateString())
                ->where($businessDate, '<', $definition->period->end->toDateString())
                ->where('point_balance_snapshots.generated_at', '<=', $cutoff);
        }
        if ($definition->reportType === 'draw_results') {
            if ($definition->qaFilter === 'normal') {
                $query->where('request.is_qa_draw', false);
            } elseif ($definition->qaFilter === 'qa') {
                $query->where('request.is_qa_draw', true);
            }
        }

        return $query;
    }

    private function sales(): Builder
    {
        return DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.status', 'succeeded')
            ->select([
                'payments.id as source_id',
                'payments.public_id',
                'users.public_id as user_public_id',
                'payments.amount',
                'payments.currency',
                'payments.paid_point_amount',
                'payments.free_point_amount',
                'payments.succeeded_at',
            ]);
    }

    private function adjustments(): Builder
    {
        return DB::table('payment_adjustments')
            ->join('payments', 'payments.id', '=', 'payment_adjustments.payment_id')
            ->select([
                'payment_adjustments.id as source_id',
                'payment_adjustments.public_id',
                'payments.public_id as payment_public_id',
                'payment_adjustments.type',
                'payment_adjustments.status',
                'payment_adjustments.amount',
                'payment_adjustments.currency',
                'payment_adjustments.succeeded_at',
            ]);
    }

    private function pointLedger(): Builder
    {
        return DB::table('point_ledger_entries as ledger')
            ->join('point_operations as operation', 'operation.id', '=', 'ledger.point_operation_id')
            ->join('users', 'users.id', '=', 'ledger.user_id')
            ->select([
                'ledger.id as source_id',
                'operation.public_id as operation_public_id',
                'users.public_id as user_public_id',
                'ledger.point_type',
                'ledger.entry_type',
                'operation.source_type',
                'ledger.amount_delta',
                'ledger.occurred_at',
                'ledger.business_date',
            ]);
    }

    private function drawResults(): Builder
    {
        return DB::table('draw_results as result')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->join('users', 'users.id', '=', 'result.user_id')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->leftJoin('catalog_ranks as rank', 'rank.id', '=', 'result.rank_id')
            ->leftJoin(
                'catalog_gacha_version_prizes as version_prize',
                'version_prize.id',
                '=',
                'result.gacha_version_prize_id'
            )
            ->leftJoin('catalog_prizes as prize', 'prize.id', '=', 'version_prize.prize_id')
            ->select([
                'result.id as source_id',
                'request.public_id as request_public_id',
                'result.public_id as result_public_id',
                'users.public_id as user_public_id',
                'version.public_id as version_public_id',
                'result.request_sequence',
                'result.result_type',
                'rank.public_id as rank_public_id',
                'prize.public_id as prize_public_id',
                'result.consumed_points',
                'result.point_back_amount',
                'request.is_qa_draw',
                'result.occurred_at',
            ]);
    }

    private function snapshots(): Builder
    {
        return DB::table('point_balance_snapshots')
            ->select([
                'id as source_id',
                'snapshot_date',
                'source_cutoff_at',
                'closing_paid_balance',
                'closing_free_balance',
                'paid_reserved_balance',
                'free_reserved_balance',
                'user_count',
                'open_lot_count',
                'is_base_date',
                'checksum',
                'generated_at',
            ]);
    }

    /** @return array<string, scalar|null> */
    private function resource(
        V2ExportDefinition $definition,
        object $row
    ): array {
        return match ($definition->reportType) {
            'sales' => [
                'business_date' => $this->businessDate($row->succeeded_at),
                'payment_id' => $row->public_id,
                'user_id' => $row->user_public_id,
                'amount' => (int) $row->amount,
                'currency' => $row->currency,
                'paid_points' => (int) $row->paid_point_amount,
                'free_points' => (int) $row->free_point_amount,
                'succeeded_at' => $this->timestamp($row->succeeded_at),
            ],
            'adjustments' => [
                'business_date' => $this->businessDate($row->succeeded_at),
                'adjustment_id' => $row->public_id,
                'payment_id' => $row->payment_public_id,
                'type' => $row->type,
                'status' => $row->status,
                'amount' => (int) $row->amount,
                'currency' => $row->currency,
                'succeeded_at' => $this->timestamp($row->succeeded_at),
            ],
            'point_ledger' => [
                'business_date' => (string) $row->business_date,
                'operation_id' => $row->operation_public_id,
                'user_id' => $row->user_public_id,
                'point_type' => $row->point_type,
                'entry_type' => $row->entry_type,
                'source_type' => $row->source_type,
                'amount_delta' => (int) $row->amount_delta,
                'occurred_at' => $this->timestamp($row->occurred_at),
            ],
            'draw_results' => [
                'business_date' => $this->businessDate($row->occurred_at),
                'draw_request_id' => $row->request_public_id,
                'draw_result_id' => $row->result_public_id,
                'user_id' => $row->user_public_id,
                'gacha_version_id' => $row->version_public_id,
                'request_sequence' => (int) $row->request_sequence,
                'result_type' => $row->result_type,
                'rank_id' => $row->rank_public_id,
                'prize_id' => $row->prize_public_id,
                'consumed_points' => (int) $row->consumed_points,
                'point_back_amount' => (int) $row->point_back_amount,
                'is_qa_draw' => (bool) $row->is_qa_draw,
                'occurred_at' => $this->timestamp($row->occurred_at),
            ],
            'point_snapshots' => [
                'business_date' => (string) $row->snapshot_date,
                'source_cutoff_at' => $this->timestamp($row->source_cutoff_at),
                'closing_paid_balance' => (int) $row->closing_paid_balance,
                'closing_free_balance' => (int) $row->closing_free_balance,
                'paid_reserved_balance' => (int) $row->paid_reserved_balance,
                'free_reserved_balance' => (int) $row->free_reserved_balance,
                'user_count' => (int) $row->user_count,
                'open_lot_count' => (int) $row->open_lot_count,
                'is_base_date' => (bool) $row->is_base_date,
                'checksum' => $row->checksum,
                'generated_at' => $this->timestamp($row->generated_at),
            ],
        };
    }

    private function idColumn(V2ExportDefinition $definition): string
    {
        return match ($definition->reportType) {
            'sales' => 'payments.id',
            'adjustments' => 'payment_adjustments.id',
            'point_ledger' => 'ledger.id',
            'draw_results' => 'result.id',
            'point_snapshots' => 'point_balance_snapshots.id',
        };
    }

    private function businessDate(mixed $timestamp): string
    {
        return CarbonImmutable::parse($timestamp)
            ->setTimezone((string) config('v2_reporting.business_timezone'))
            ->toDateString();
    }

    private function timestamp(mixed $timestamp): string
    {
        return CarbonImmutable::parse($timestamp)->toIso8601String();
    }
}
