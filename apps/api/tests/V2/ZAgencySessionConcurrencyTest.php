<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2AgencyPortalService;
use App\Domain\Identity\Services\V2AgencyService;
use App\Domain\Identity\Services\V2SessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZAgencySessionConcurrencyTest extends TestCase
{
    public function test_login_and_admin_stop_serialize_without_live_sessions(): void
    {
        self::assertTrue(function_exists('pcntl_fork'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cache.default' => 'array']);
        Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        $hash = app(V2AgencyPasswordPolicy::class)->hash('Initial123');
        $agencyPublicId = (string) Str::uuid7();
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => $agencyPublicId, 'company_name' => 'Concurrent QA', 'contact_name' => 'QA',
            'phone' => '0311112222', 'email' => 'concurrent@example.test', 'normalized_email' => 'concurrent@example.test',
            'address' => 'Synthetic', 'login_id' => '000001', 'password_hash' => $hash,
            'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_advertising_codes')->insert(['agency_id' => $agencyId, 'code' => 'AD000001']);
        $adminPublicId = (string) Str::uuid7();
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => $adminPublicId, 'email_display' => 'owner@example.test', 'email_normalized' => 'owner@example.test',
            'email_verified_at' => now(), 'password_hash' => $hash, 'role' => 'owner', 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminSession = app(V2SessionManager::class)->issue(V2Realm::Admin, $adminId, true);
        $sessionHash = hash('sha256', $adminSession['token']);
        $context = new V2AdminAuthorizationContext($adminId, $adminPublicId, V2AdminRole::Owner, $sessionHash, hash('sha256', $sessionHash), (string) Str::uuid7());
        $barrier = sys_get_temp_dir().'/agency-race-'.Str::uuid7();
        $children = [];
        DB::disconnect();
        try {
            foreach (['login', 'suspend'] as $operation) {
                $process = pcntl_fork();
                self::assertNotSame(-1, $process);
                if ($process === 0) {
                    DB::purge();
                    while (! file_exists($barrier)) usleep(1000);
                    try {
                        if ($operation === 'login') {
                            try {
                                app(V2AgencyPortalService::class)->login(Request::create('/agency/api/v2/auth/login', 'POST'), ['login_id' => '000001', 'password' => 'Initial123']);
                            } catch (V2AuthenticationException $exception) {
                                if ($exception->status !== 401) throw $exception;
                            }
                        } else {
                            app(V2AgencyService::class)->mutate($context, 'suspend', $agencyPublicId, ['expected_revision' => 1], (string) Str::uuid7());
                        }
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                $children[] = $process;
            }
            file_put_contents($barrier, 'start');
            foreach ($children as $process) {
                pcntl_waitpid($process, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::reconnect();
            self::assertSame('suspended', DB::table('agencies')->where('id', $agencyId)->value('status'));
            self::assertSame(0, DB::table('agency_sessions')->where('agency_id', $agencyId)->whereNull('revoked_at')->count());
        } finally {
            DB::reconnect();
            @unlink($barrier);
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        }
    }
}
