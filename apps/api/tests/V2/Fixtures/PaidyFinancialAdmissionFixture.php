<?php

namespace Tests\V2\Fixtures;

use App\Domain\MemberRank\Services\V2MemberRankPrelaunchActivation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;

trait PaidyFinancialAdmissionFixture
{
    use MemberRankAccountingFixture;

    protected function requirePaidyFinancialAdmission(): void
    {
        self::assertTrue(app()->environment('testing'));
        self::assertSame('pgsql', config('database.default'));
        self::assertSame(0, DB::transactionLevel());
        if ((int) DB::table('financial_admission_generations')->value('generation') === 0) {
            // Only the disposable test schema: publish through the real authority
            // service, retaining the generation and financial write guards.
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
            $this->configureRankFixture();
            $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
            $admin = $this->rankAdmin();
            $setting = DB::table('member_rank_settings')->sole();
            $authority = (string) Str::uuid7();
            DB::table('member_rank_prelaunch_authorizations')->insert([
                'public_id' => $authority, 'site_code' => 'oripoke-prelaunch',
                'setting_public_id' => $setting->public_id,
                'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
                'expected_revision' => $setting->revision,
                'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
            ]);
            app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), [
                'authorization_id' => $authority, 'expected_revision' => (int) $setting->revision,
            ]);
        }
        self::assertSame(1, (int) DB::table('financial_admission_generations')->value('generation'));
        self::assertTrue(DB::table('member_rank_settings')->value('enabled'));
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
    }
}
