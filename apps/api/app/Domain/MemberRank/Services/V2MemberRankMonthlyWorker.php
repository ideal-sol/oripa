<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class V2MemberRankMonthlyWorker
{
    private bool $stopped = false;

    public function __construct(
        private readonly V2MemberRankResolver $ranks,
        private readonly V2PointTransactionRunner $transactions
    ) {
    }

    public function requestStop(): void
    {
        $this->stopped = true;
    }

    public function run(string $worker, int $limit = 100): int
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/', $worker) !== 1 || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Member rank worker identity or limit is invalid.');
        }
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            return 0;
        }
        $owner = hash('sha256', $worker.'|'.Str::uuid7());
        $job = $this->claim($owner);
        if ($job === null) {
            return 0;
        }
        $processed = 0;
        try {
            while ($processed < $limit && ! $this->stopped) {
                if (! $this->processNext($job->month, $owner)) {
                    break;
                }
                $processed++;
            }
            $this->release($job->month, $owner, false);
        } catch (Throwable) {
            $this->release($job->month, $owner, true);
        }

        return $processed;
    }

    public function progress(?CarbonImmutable $instant = null): array
    {
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            return ['enabled' => false, 'remaining' => 0, 'provisional' => false, 'job' => null, 'last_completed_month' => null];
        }
        $month = ($instant ?? CarbonImmutable::now())->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString();
        $remaining = $this->pending($month)->count();

        return [
            'enabled' => true, 'remaining' => $remaining, 'provisional' => $remaining > 0,
            'job' => DB::table('member_rank_monthly_jobs')->where('month', $month)->first(),
            'last_completed_month' => DB::table('member_rank_monthly_jobs')->where('status', 'completed')->max('month'),
        ];
    }

    private function claim(string $owner): ?object
    {
        return $this->transactions->run(function () use ($owner): ?object {
            $now = CarbonImmutable::now();
            $month = $now->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString();
            $timestamp = V2DatabaseTimestamp::format($now);
            DB::table('member_rank_monthly_jobs')->insertOrIgnore([
                'month' => $month, 'next_attempt_at' => $timestamp,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $job = DB::table('member_rank_monthly_jobs')->where('month', '<=', $month)
                ->where(function (Builder $query) use ($timestamp): void {
                    $query->where(fn (Builder $pending) => $pending->where('status', 'pending')->where('next_attempt_at', '<=', $timestamp))
                        ->orWhere(fn (Builder $expired) => $expired->where('status', 'processing')->where('lease_expires_at', '<=', $timestamp));
                })->orderBy('month')->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($job === null) {
                return null;
            }
            $failures = (int) $job->failures + ($job->status === 'processing' ? 1 : 0);
            if ($failures >= 3) {
                DB::table('member_rank_monthly_jobs')->where('month', $job->month)->update([
                    'status' => 'failed', 'failures' => 3, 'last_error' => 'lease_expired',
                    'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $timestamp,
                ]);

                return null;
            }
            DB::table('member_rank_monthly_jobs')->where('month', $job->month)->update([
                'status' => 'processing', 'failures' => $failures, 'lease_owner' => $owner,
                'lease_expires_at' => V2DatabaseTimestamp::format($now->addSeconds(60)),
                'heartbeat_at' => $timestamp, 'started_at' => $job->started_at ?? $timestamp, 'updated_at' => $timestamp,
            ]);

            return $job;
        });
    }

    private function processNext(string $month, string $owner): bool
    {
        return $this->transactions->run(function () use ($month, $owner): bool {
            $job = $this->ownedJob($month, $owner);
            if ($job === null) {
                return false;
            }
            $userId = $this->pending($month)->where('user_id', '>', $job->cursor_user_id)->orderBy('user_id')->value('user_id');
            $now = CarbonImmutable::now();
            $timestamp = V2DatabaseTimestamp::format($now);
            if ($userId === null) {
                $remaining = $this->pending($month)->exists();
                DB::table('member_rank_monthly_jobs')->where('month', $month)->update([
                    'status' => $remaining ? 'pending' : 'completed', 'cursor_user_id' => $remaining ? 0 : $job->cursor_user_id,
                    'completed_at' => $remaining ? null : $timestamp, 'lease_owner' => null,
                    'lease_expires_at' => null, 'updated_at' => $timestamp,
                ]);

                return false;
            }
            $this->ranks->materialize((int) $userId);
            DB::table('member_rank_monthly_jobs')->where('month', $month)->update([
                'cursor_user_id' => $userId, 'processed_count' => (int) $job->processed_count + 1,
                'failures' => 0, 'last_error' => null, 'heartbeat_at' => $timestamp,
                'lease_expires_at' => V2DatabaseTimestamp::format($now->addSeconds(60)), 'updated_at' => $timestamp,
            ]);

            return true;
        });
    }

    private function release(string $month, string $owner, bool $failed): void
    {
        $this->transactions->run(function () use ($month, $owner, $failed): void {
            $job = $this->ownedJob($month, $owner);
            if ($job === null) {
                return;
            }
            $failures = (int) $job->failures + ($failed ? 1 : 0);
            $now = CarbonImmutable::now();
            DB::table('member_rank_monthly_jobs')->where('month', $month)->update([
                'status' => $failures >= 3 ? 'failed' : 'pending', 'failures' => $failures,
                'lease_owner' => null, 'lease_expires_at' => null,
                'next_attempt_at' => V2DatabaseTimestamp::format($failed ? $now->addSeconds(5 * (2 ** $failures)) : $now),
                'last_error' => $failed ? 'monthly_judgment_failed' : $job->last_error,
                'updated_at' => V2DatabaseTimestamp::format($now),
            ]);
        });
    }

    private function ownedJob(string $month, string $owner): ?object
    {
        return DB::table('member_rank_monthly_jobs')->where('month', $month)->where('status', 'processing')
            ->where('lease_owner', $owner)->where('lease_expires_at', '>', V2DatabaseTimestamp::format(CarbonImmutable::now()))
            ->lockForUpdate()->first();
    }

    private function pending(string $month): Builder
    {
        return DB::table('member_rank_states')->where('level', '>', 1)->where('baseline_month', '<', $month)
            ->whereNull('frozen_at')->whereIn('user_id', DB::table('users')->select('id')->whereNotIn('state', ['closed', 'anonymized']));
    }
}
