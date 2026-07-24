<?php

namespace App\Domain\Point\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Models\V2\PointReconciliationDiscrepancy;
use App\Models\V2\PointReconciliationRun;
use App\Models\V2\Wallet;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class V2PointReconciliationService
{
    public function __construct(
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2PointLedgerService $ledger,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function run(string|DateTimeInterface $targetDate): PointReconciliationRun
    {
        $date = CarbonImmutable::parse($targetDate, 'Asia/Tokyo')->toDateString();

        return $this->transactions->run(function () use ($date): PointReconciliationRun {
            $run = new PointReconciliationRun();
            $run->forceFill([
                'target_date' => $date,
                'status' => 'running',
                'checked_wallet_count' => 0,
                'discrepancy_count' => 0,
                'initiated_by' => 'system',
                'started_at' => now(),
            ])->save();
            $checked = 0;
            $discrepancies = 0;
            foreach (Wallet::query()->orderBy('id')->get() as $wallet) {
                $checked++;
                $lotBalances = DB::table('point_lots')
                    ->selectRaw('point_type, COALESCE(SUM(remaining_amount), 0) AS balance')
                    ->where('user_id', $wallet->user_id)
                    ->groupBy('point_type')
                    ->pluck('balance', 'point_type');
                $ledgerBalances = $this->ledger->rebuild((int) $wallet->user_id);
                foreach (['paid', 'free'] as $pointType) {
                    $walletBalance = (int) $wallet->{$pointType.'_balance'};
                    $lotBalance = (int) ($lotBalances[$pointType] ?? 0);
                    $ledgerBalance = $ledgerBalances[$pointType];
                    foreach (
                        [
                            'wallet_lot' => $lotBalance,
                            'wallet_ledger' => $ledgerBalance,
                        ] as $type => $actual
                    ) {
                        if ($walletBalance === $actual) {
                            continue;
                        }
                        $record = new PointReconciliationDiscrepancy();
                        $record->forceFill([
                            'reconciliation_run_id' => $run->id,
                            'user_id' => $wallet->user_id,
                            'point_type' => $pointType,
                            'discrepancy_type' => $type,
                            'expected_amount' => $walletBalance,
                            'actual_amount' => $actual,
                            'source_ids' => [$wallet->id],
                            'resolved' => false,
                        ])->save();
                        $discrepancies++;
                    }
                }
            }
            $run->forceFill([
                'status' => 'completed',
                'checked_wallet_count' => $checked,
                'discrepancy_count' => $discrepancies,
                'completed_at' => now(),
            ])->save();
            $this->audit->record('point.reconciliation_completed', [
                'outcome' => $discrepancies === 0 ? 'success' : 'failure',
                'reason_code' => $discrepancies === 0 ? null : 'point_discrepancy_detected',
                'metadata' => [
                    'run_public_id' => $run->public_id,
                    'target_date' => $date,
                    'checked_wallet_count' => $checked,
                    'discrepancy_count' => $discrepancies,
                    'automatic_repair' => false,
                ],
            ]);

            return $run->refresh();
        });
    }
}
