<?php

namespace App\Console\Commands\V2;

use App\Domain\MemberRank\Services\V2MemberRankCumulativePoints;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\MemberRank\Services\V2MemberRankTransferService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillV2MemberRankTransfers extends Command
{
    protected $signature = 'v2:member-rank:backfill-transfers {--apply : Apply after separately approved dry-run review}';

    protected $description = 'Dry-run the one-time legacy member-rank transfer allowance correction';

    public function handle(): int
    {
        if (! DB::table('member_rank_settings')->where('id', 1)->value('enabled')) {
            $this->error('Member ranks are not enabled.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Applying approved transfer correction.' : 'DRY RUN: no writes.');
        DB::table('member_rank_states')->whereNotNull('transferred_at')->whereNull('frozen_at')->orderBy('user_id')
            ->chunkById(100, function ($states) use ($apply): void {
                foreach ($states as $candidate) {
                    app(V2PointTransactionRunner::class)->run(function () use ($candidate, $apply): void {
                        $user = DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->firstOrFail();
                        $state = DB::table('member_rank_states')->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                        if ($user->state !== 'active' || $state->frozen_at !== null
                            || DB::table('member_rank_transfer_allowances')->where('user_id', $user->id)->exists()
                            || DB::table('member_rank_transfer_histories')->where('user_id', $user->id)->where('source', 'backfill')->exists()) {
                            return;
                        }
                        $instant = CarbonImmutable::now()->startOfSecond();
                        $version = app(V2MemberRankSettingsService::class)->effectiveAt($instant);
                        $points = max(0, (int) $version['ranks'][(int) $state->level - 1]['reach_line']
                            - app(V2MemberRankCumulativePoints::class)->total((int) $user->id, false, false));
                        $this->line(json_encode(['user_id' => $user->public_id, 'level' => (int) $state->level, 'points' => $points], JSON_THROW_ON_ERROR));
                        if ($apply) {
                            app(V2MemberRankTransferService::class)->save((int) $user->id, [
                                'status' => 'transferred', 'equivalent_level' => (int) $state->level,
                                'points' => $points, 'reason' => 'Legacy transferred_at correction',
                            ], null, $instant, 0);
                        }
                    });
                }
            }, 'user_id');

        return self::SUCCESS;
    }
}
