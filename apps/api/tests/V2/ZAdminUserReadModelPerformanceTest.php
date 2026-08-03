<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2AdminUserReadService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZAdminUserReadModelPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config(['cache.default' => 'array', 'v2_audit.business_timezone' => 'Asia/Tokyo']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_user_list_query_count_is_bounded_without_n_plus_one(): void
    {
        if (getenv('V2_ADMIN_USER_READ_PERFORMANCE_TEST') !== '1') {
            self::markTestSkipped('Admin User Read performance test is opt-in.');
        }
        $passwordHash = app(V2PasswordPolicy::class)->hash('valid password');
        for ($index = 0; $index < 100; $index++) {
            $email = 'performance-user-'.$index.'@example.test';
            $userId = DB::table('users')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'display_name' => 'User '.$index,
                'email_display' => $email,
                'email_normalized' => $email,
                'email_verified_at' => now(),
                'password_hash' => $passwordHash,
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('wallets')->insert([
                'user_id' => $userId,
                'paid_balance' => $index,
                'free_balance' => $index,
                'paid_reserved_balance' => 0,
                'free_reserved_balance' => 0,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $started = hrtime(true);
        $page = app(V2AdminUserReadService::class)->users($this->context(), null, 100);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        self::assertCount(100, $page['items']);
        self::assertLessThanOrEqual(8, $queries);
        self::assertLessThan(1000, $elapsed);
        fwrite(STDERR, sprintf(
            "MIG-061G performance rows=100 queries=%d elapsed_ms=%.2f\n",
            $queries,
            $elapsed
        ));
    }

    private function context(): V2AdminAuthorizationContext
    {
        $email = 'performance-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Operator,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', random_bytes(32));

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
