<?php

namespace Tests\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class LineAutomatedMigrationTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_upgrade_preserves_existing_rank_uuid_payment_and_history_and_starts_off(): void
    {
        self::assertTrue(app()->environment('testing'));
        $schema = 'd4_upgrade_'.bin2hex(random_bytes(8));
        $original = config('database.connections.pgsql');
        $directory = storage_path('framework/testing/'.$schema);
        mkdir($directory, 0700, true);
        DB::statement('CREATE SCHEMA '.$schema);
        try {
            foreach (glob(database_path('migrations-v2/*.php')) as $file) {
                if (! str_contains($file, '_000113_')) copy($file, $directory.'/'.basename($file));
            }
            config(['database.connections.pgsql.search_path' => $schema]); DB::purge('pgsql');
            self::assertSame(0, Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]));
            $this->configureRankFixture(); $this->enableRankFixture();
            $user = $this->rankUser(); $this->settleRankPayment($user, 100000);
            $before = [];
            foreach (['member_rank_changes', 'member_rank_states', 'payments', 'payment_point_grants', 'point_operations', 'outbox_messages'] as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            self::assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]));
            foreach ($before as $table => $rows) self::assertSame($rows, DB::table($table)->get()->toJson(), $table);
            self::assertSame(4, DB::table('line_automated_templates')->where('revision', 0)->count());
            self::assertSame(0, DB::table('line_automated_template_versions')->count());
            $migration = require database_path('migrations-v2/2026_11_16_000113_add_v2_line_automated_notifications.php');
            try { $migration->down(); self::fail('Reverse migration erased history'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Forward-only', $e->getMessage()); }
            self::assertSame(4, DB::table('line_automated_templates')->count());
            // CI rebuilds this disposable schema; PostgreSQL functions survive table recreation.
            // Exercise the actual fresh command twice without disabling the history guard.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                self::assertSame(0, Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]));
                self::assertSame(4, DB::table('line_automated_templates')->where('revision', 0)->count());
            }
        } finally {
            while (DB::transactionLevel() > 0) DB::rollBack();
            DB::purge('pgsql'); config(['database.connections.pgsql' => $original]);
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
            foreach (glob($directory.'/*.php') as $file) unlink($file);
            rmdir($directory);
            Carbon::setTestNow(); CarbonImmutable::setTestNow();
        }
    }
}
