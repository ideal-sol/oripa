<?php

namespace App\Domain\Point\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Models\V2\PointBalanceSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PointSnapshotService
{
    public function __construct(
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function generate(string|DateTimeInterface $snapshotDate): PointBalanceSnapshot
    {
        $date = CarbonImmutable::parse($snapshotDate, 'Asia/Tokyo')->startOfDay();
        if ($date->isFuture()) {
            throw new V2PointException('Future point balance snapshots are prohibited.');
        }
        $start = $date->utc();
        $cutoff = $date->addDay()->utc();

        return $this->transactions->run(function () use ($date, $start, $cutoff): PointBalanceSnapshot {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('v2_point_snapshot', 0))");
            $existing = PointBalanceSnapshot::query()
                ->where('snapshot_date', $date->toDateString())
                ->lockForUpdate()
                ->first();
            $values = $this->calculate($start, $cutoff);
            $values['snapshot_date'] = $date->toDateString();
            $values['source_cutoff_at'] = $cutoff;
            $values['calculation_method'] = 'ledger_cutoff';
            $values['is_base_date'] = in_array($date->format('m-d'), ['03-31', '09-30'], true);
            $values['generated_at'] = now();
            $values['generation_run_id'] = (string) Str::uuid7();
            $values['checksum'] = hash(
                'sha256',
                json_encode($this->checksumValues($values), JSON_THROW_ON_ERROR)
            );
            $previousChecksum = $existing?->checksum;
            if ($existing === null) {
                $snapshot = new PointBalanceSnapshot();
                $snapshot->forceFill($values)->save();
            } else {
                $existing->forceFill($values)->save();
                $snapshot = $existing;
            }
            $this->audit->record('point.snapshot_generated', [
                'metadata' => [
                    'snapshot_date' => $date->toDateString(),
                    'generation_run_id' => $values['generation_run_id'],
                    'checksum' => $values['checksum'],
                    'previous_checksum' => $previousChecksum,
                    'regenerated' => $existing !== null,
                ],
            ]);

            return $snapshot->refresh();
        });
    }

    /**
     * @return array<string, int>
     */
    private function calculate(CarbonImmutable $start, CarbonImmutable $cutoff): array
    {
        $opening = $this->balanceBefore($start);
        $closing = $this->balanceBefore($cutoff);
        $flows = DB::table('point_ledger_entries')
            ->selectRaw(
                <<<'SQL'
                    COALESCE(SUM(amount_delta) FILTER (
                        WHERE point_type = 'paid' AND entry_type IN ('grant', 'restore')
                    ), 0) AS granted_paid,
                    COALESCE(SUM(amount_delta) FILTER (
                        WHERE point_type = 'free' AND entry_type IN ('grant', 'restore')
                    ), 0) AS granted_free,
                    COALESCE(-SUM(amount_delta) FILTER (
                        WHERE point_type = 'paid' AND entry_type = 'spend'
                    ), 0) AS consumed_paid,
                    COALESCE(-SUM(amount_delta) FILTER (
                        WHERE point_type = 'free' AND entry_type = 'spend'
                    ), 0) AS consumed_free,
                    COALESCE(-SUM(amount_delta) FILTER (
                        WHERE point_type = 'free' AND entry_type = 'expire'
                    ), 0) AS expired_free,
                    COALESCE(-SUM(amount_delta) FILTER (
                        WHERE point_type = 'paid' AND entry_type = 'reverse'
                    ), 0) AS reversed_paid,
                    COALESCE(-SUM(amount_delta) FILTER (
                        WHERE point_type = 'free' AND entry_type = 'reverse'
                    ), 0) AS reversed_free
                SQL
            )
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $cutoff)
            ->first();
        $userCount = DB::table('point_ledger_entries')
            ->where('occurred_at', '<', $cutoff)
            ->distinct()
            ->count('user_id');
        $openLotCount = DB::query()->fromSub(
            DB::table('point_ledger_entries')
                ->select('point_lot_id')
                ->selectRaw('SUM(amount_delta) AS balance')
                ->whereNotNull('point_lot_id')
                ->where('occurred_at', '<', $cutoff)
                ->groupBy('point_lot_id')
                ->havingRaw('SUM(amount_delta) > 0'),
            'open_lots'
        )->count();

        return [
            'opening_paid_balance' => $opening['paid'],
            'opening_free_balance' => $opening['free'],
            'granted_paid_amount' => (int) $flows->granted_paid,
            'granted_free_amount' => (int) $flows->granted_free,
            'consumed_paid_amount' => (int) $flows->consumed_paid,
            'consumed_free_amount' => (int) $flows->consumed_free,
            'expired_free_amount' => (int) $flows->expired_free,
            'reversed_paid_amount' => (int) $flows->reversed_paid,
            'reversed_free_amount' => (int) $flows->reversed_free,
            'closing_paid_balance' => $closing['paid'],
            'closing_free_balance' => $closing['free'],
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'user_count' => $userCount,
            'open_lot_count' => $openLotCount,
        ];
    }

    /**
     * @return array{paid: int, free: int}
     */
    private function balanceBefore(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('point_ledger_entries')
            ->selectRaw('point_type, COALESCE(SUM(amount_delta), 0) AS balance')
            ->where('occurred_at', '<', $cutoff)
            ->groupBy('point_type')
            ->get();
        $balance = ['paid' => 0, 'free' => 0];
        foreach ($rows as $row) {
            $balance[$row->point_type] = (int) $row->balance;
        }
        if ($balance['paid'] < 0 || $balance['free'] < 0) {
            throw new V2PointException('Snapshot cutoff produced a negative balance.');
        }

        return $balance;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function checksumValues(array $values): array
    {
        unset($values['generated_at'], $values['generation_run_id'], $values['checksum']);
        ksort($values);
        foreach ($values as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $values[$key] = CarbonImmutable::instance($value)->utc()->toIso8601String();
            }
        }

        return $values;
    }
}
