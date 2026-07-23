<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Enums\PointType;
use App\Models\PointBalanceSnapshot;
use App\Models\PointLot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PointBalanceSnapshotService
{
    public function createForDate(CarbonInterface|string|null $date = null): array
    {
        $snapshotDate = $this->normalizeDate($date);
        $balances = $this->calculateBalances();

        $snapshot = DB::transaction(function () use ($snapshotDate, $balances): PointBalanceSnapshot {
            return PointBalanceSnapshot::query()->updateOrCreate(
                ['snapshot_date' => $snapshotDate->toDateString()],
                [
                    'paid_unused_balance' => $balances['paid_unused_balance'],
                    'free_unused_balance' => $balances['free_unused_balance'],
                    'is_base_date' => $this->isBaseDate($snapshotDate),
                ],
            );
        });

        return [
            'snapshot' => $snapshot,
            'snapshot_date' => $snapshot->snapshot_date->toDateString(),
            'paid_unused_balance' => (int) $snapshot->paid_unused_balance,
            'free_unused_balance' => (int) $snapshot->free_unused_balance,
            'is_base_date' => (bool) $snapshot->is_base_date,
        ];
    }

    public function calculateBalances(): array
    {
        $now = CarbonImmutable::now($this->timezone());

        $paid = PointLot::query()
            ->where('point_type', PointType::Paid->value)
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount');

        $free = PointLot::query()
            ->where('point_type', PointType::Free->value)
            ->where('remaining_amount', '>', 0)
            ->where('expire_at', '>', $now)
            ->sum('remaining_amount');

        return [
            'paid_unused_balance' => (int) $paid,
            'free_unused_balance' => (int) $free,
        ];
    }

    public function isBaseDate(CarbonInterface $date): bool
    {
        $jstDate = CarbonImmutable::instance($date)->setTimezone($this->timezone());

        return in_array($jstDate->format('m-d'), ['03-31', '09-30'], true);
    }

    private function normalizeDate(CarbonInterface|string|null $date): CarbonImmutable
    {
        if ($date === null) {
            return CarbonImmutable::now($this->timezone())->subDay()->startOfDay();
        }

        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($this->timezone())->startOfDay();
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        return $parsed->startOfDay();
    }

    private function timezone(): string
    {
        return config('app.timezone', 'Asia/Tokyo');
    }
}
