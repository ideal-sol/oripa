<?php

namespace Tests\V2;

use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class ZMemberRankGachaConcurrencyTest extends TestCase
{
    use MemberRankGachaFixture;

    public function test_cross_gacha_daily_and_manual_rank_changes_serialize_on_user_lock(): void
    {
        if (getenv('V2_M5_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the explicit isolated M5 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m5_synthetic', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::table('catalog_gachas')->count());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        $this->importRankGachas();
        $first = $this->rankGacha('login_daily', 'at_least', 1);
        $second = $this->rankGacha('login_daily', 'at_most', 7);
        $offUser = $this->fundedRankUser(2);
        $off = $this->race($offUser, $second, fn () => $this->rankDraw($offUser, $first));
        self::assertNull($off['draw']['member_rank']);
        self::assertSame(2, DB::table('draw_requests')->where('user_id', $offUser->id)->count());
        $this->enableRankFixture();
        $user = $this->fundedRankUser(2);
        $on = $this->race($user, $second, fn () => $this->rankDraw($user, $first));
        self::assertSame('DAILY_DRAW_LIMIT_EXCEEDED', $on['code']);
        self::assertSame(1, DB::table('draw_requests')->where('user_id', $user->id)->count());
        $standard = $this->rankGacha('standard', 'only', 4);
        $admin = $this->rankAdmin();
        foreach ([['gold', 4, true], ['bronze', 2, false]] as [$code, $level, $allowed]) {
            $input = ['rank' => $code, 'kind' => 'support', 'reason' => 'Synthetic M5 lock verification',
                'skip_count' => 1, 'grant_bonus' => false];
            $service = app(V2MemberRankAdminService::class);
            $preview = $service->preview($admin, $user->public_id, 'rank', $input);
            $outcome = $this->race($user, $standard, fn () => $service->write($admin, $user->public_id, 'rank',
                [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7()));
            if ($allowed) {
                self::assertSame($level, $outcome['draw']['member_rank']['level']);
            } else {
                self::assertSame('GACHA_MEMBER_RANK_NOT_ELIGIBLE', $outcome['code']);
            }
        }
        self::assertSame(2, DB::table('draw_requests')->where('user_id', $user->id)->count());
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function race(User $user, object $gacha, callable $parentWrite): array
    {
        $path = tempnam(sys_get_temp_dir(), 'm5-race-');
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::disconnect();
        $processId = pcntl_fork();
        self::assertNotSame(-1, $processId);
        if ($processId === 0) {
            fclose($parentSocket);
            fread($childSocket, 1);
            DB::reconnect();
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            try {
                DB::statement("SET lock_timeout = '30s'");
                $result = ['draw' => $this->rankDraw(User::query()->findOrFail($user->id), $gacha)];
            } catch (V2DrawException $exception) {
                $result = ['code' => $exception->errorCode];
            } catch (\Throwable $exception) {
                $result = ['error' => $exception->getMessage()];
            }
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        DB::reconnect();
        try {
            DB::transaction(function () use ($user, $parentSocket, $parentWrite): void {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
                fwrite($parentSocket, '1');
                $backend = (int) fgets($parentSocket);
                $waiting = false;
                for ($attempt = 0; $attempt < 200; $attempt++) {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend])?->wait_event_type === 'Lock';
                    if ($waiting) {
                        break;
                    }
                    usleep(50000);
                }
                self::assertTrue($waiting, 'Competing Draw must wait on the User Lock.');
                $parentWrite();
            });
        } finally {
            pcntl_waitpid($processId, $status);
            fclose($parentSocket);
        }
        $outcome = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertArrayNotHasKey('error', $outcome, json_encode($outcome));

        return $outcome;
    }
}
