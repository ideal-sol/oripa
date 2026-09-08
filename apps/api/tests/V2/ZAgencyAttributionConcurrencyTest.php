<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Services\V2AgencyAttributionService;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZAgencyAttributionConcurrencyTest extends TestCase
{
    public function test_registration_waits_for_agency_stop_and_rechecks_committed_state(): void
    {
        self::assertTrue(function_exists('pcntl_fork'));
        config(['cache.default' => 'array']);
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send');
        Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_name' => 'Concurrent QA', 'contact_name' => 'QA',
            'phone' => '0311112222', 'email' => 'race@example.test', 'normalized_email' => 'race@example.test',
            'address' => 'Synthetic', 'login_id' => '000001',
            'password_hash' => app(V2AgencyPasswordPolicy::class)->hash('Initial123'),
            'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_advertising_codes')->insert(['agency_id' => $agencyId, 'code' => 'Ab12Cd34']);
        self::assertTrue(app(V2AgencyAttributionService::class)->isValid('Ab12Cd34'));
        $barrier = sys_get_temp_dir().'/agency-attribution-'.Str::uuid7();
        DB::disconnect();
        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            DB::purge();
            while (! file_exists($barrier)) usleep(1000);
            try {
                DB::statement("SET application_name = 'agency004a-registration'");
                app(V2UserAuthenticationService::class)->register('race-user@example.test', 'valid user password', '/', '192.0.2.44', 'Ab12Cd34');
                DB::disconnect();
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first();
            DB::table('agencies')->where('id', $agencyId)->update(['status' => 'suspended']);
            file_put_contents($barrier, 'start');
            $waiting = false;
            for ($attempt = 0; $attempt < 500; $attempt++) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'agency004a-registration')->where('wait_event_type', 'Lock')->exists();
                if ($waiting) break;
                usleep(10000);
            }
            self::assertTrue($waiting, 'Registration must wait on the Agency row before attribution');
            DB::commit();
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame(1, DB::table('users')->where('email_normalized', 'race-user@example.test')->count());
            self::assertSame(0, DB::table('user_advertising_attributions')->count());
        } finally {
            while (DB::transactionLevel() > 0) DB::rollBack();
            pcntl_waitpid($child, $status);
            @unlink($barrier);
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        }
    }
}
