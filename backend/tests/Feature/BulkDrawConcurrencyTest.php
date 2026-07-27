<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\PointLot;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkDrawConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_user_different_keys_are_serialized_without_double_spending(): void
    {
        [$user, $gacha] = $this->createFixture(userCount: 1, drawCapacity: 200);

        $this->runConcurrentDraws([
            [$user->id, $gacha->id, 'same-user-a'],
            [$user->id, $gacha->id, 'same-user-b'],
        ]);

        $this->assertSame(200, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertSame(200, DrawResult::query()->count());
        $this->assertSame(200, DrawResult::query()->distinct()->count('draw_sequence_number'));
        $this->assertSame(200, UserPrize::query()->distinct()->count('draw_result_id'));
        $this->assertSame(2, DrawRequest::query()->count());
        $this->assertDatabaseCount('point_ledgers', 2);
    }

    public function test_different_users_on_same_gacha_do_not_exceed_inventory(): void
    {
        [$firstUser, $gacha, $secondUser] = $this->createFixture(userCount: 2, drawCapacity: 200);

        $this->runConcurrentDraws([
            [$firstUser->id, $gacha->id, 'different-user-a'],
            [$secondUser->id, $gacha->id, 'different-user-b'],
        ]);

        $this->assertSame(200, $gacha->refresh()->sold_count);
        $this->assertSame(200, DrawResult::query()->count());
        $this->assertSame(200, DrawResult::query()->distinct()->count('draw_sequence_number'));
        $this->assertSame(200, UserPrize::query()->count());
        $this->assertSame(200, GachaPrize::query()->sum('won_count'));
        $this->assertSame(0, $firstUser->wallet->refresh()->paid_balance);
        $this->assertSame(0, $secondUser->wallet->refresh()->paid_balance);
    }

    public function test_concurrent_same_key_replays_one_committed_bulk_result(): void
    {
        [$user, $gacha] = $this->createFixture(userCount: 1, drawCapacity: 200);

        $this->runConcurrentDraws([
            [$user->id, $gacha->id, 'same-key'],
            [$user->id, $gacha->id, 'same-key'],
        ]);

        $this->assertSame(100, $gacha->refresh()->sold_count);
        $this->assertSame(100, DrawResult::query()->count());
        $this->assertSame(100, UserPrize::query()->count());
        $this->assertSame(1, DrawRequest::query()->count());
        $this->assertDatabaseCount('point_ledgers', 1);
    }

    /**
     * @return array{0: User, 1: Gacha, 2?: User}
     */
    private function createFixture(int $userCount, int $drawCapacity): array
    {
        $users = User::factory()->count($userCount)->create();
        $gacha = Gacha::factory()->create([
            'price' => 1,
            'total_count' => $drawCapacity,
            'sold_count' => 0,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => 0,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => $drawCapacity,
            'won_count' => 0,
            'is_active' => true,
        ]);

        foreach ($users as $user) {
            $balance = $userCount === 1 ? 200 : 100;
            Wallet::query()->create([
                'user_id' => $user->id,
                'paid_balance' => $balance,
                'free_balance' => 0,
            ]);
            PointLot::query()->create([
                'user_id' => $user->id,
                'point_type' => PointType::Paid,
                'granted_amount' => $balance,
                'remaining_amount' => $balance,
                'source_type' => PointLotSourceType::Purchase,
                'source_id' => null,
                'granted_at' => now()->subDay(),
                'expire_at' => null,
            ]);
        }

        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 0],
                ],
            ],
        ], AdminUser::factory()->create());

        return $users->count() === 1
            ? [$users->first(), $gacha]
            : [$users->first(), $gacha, $users->last()];
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: string}> $draws
     */
    private function runConcurrentDraws(array $draws): void
    {
        $children = [];

        foreach ($draws as [$userId, $gachaId, $key]) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork concurrent bulk draw process.');
            }

            if ($pid === 0) {
                try {
                    DB::disconnect();
                    DB::reconnect();
                    app(DrawService::class)->draw(
                        User::query()->findOrFail($userId),
                        Gacha::query()->findOrFail($gachaId),
                        100,
                        $key,
                    );
                    exit(0);
                } catch (\Throwable $exception) {
                    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::disconnect();
        DB::reconnect();
    }
}
