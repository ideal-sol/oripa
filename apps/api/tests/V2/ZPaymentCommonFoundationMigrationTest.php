<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZPaymentCommonFoundationMigrationTest extends TestCase
{
    public function test_upgrade_classifies_exact_existing_fincode_rows_without_rewriting_them(): void
    {
        self::assertTrue(app()->environment('testing'));
        $migration = '2026_10_26_000099_add_v2_payment_common_foundation.php';
        $paths = array_values(array_filter(glob(database_path('migrations-v2/*.php')),
            static fn (string $path): bool => basename($path) !== $migration));
        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);
        try {
            $user = User::query()->create([
                'email_display' => 'migration@example.test', 'email_normalized' => 'migration@example.test',
                'email_verified_at' => now(), 'state' => 'active',
                'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic migration password'),
            ]);
            $ids = [];
            foreach (['created', 'succeeded', 'failed', 'canceled'] as $status) {
                $ids[] = DB::table('payments')->insertGetId([
                    'public_id' => (string) Str::uuid7(), 'user_id' => $user->id,
                    'provider_code' => 'fincode', 'provider_payment_id' => 'historical-'.$status,
                    'status' => $status, 'payment_method' => 'paypay', 'amount' => 1000,
                    'currency' => 'JPY', 'paid_point_amount' => 1000, 'free_point_amount' => 0,
                    'plan_name_snapshot' => 'Historical synthetic plan', 'plan_code_snapshot' => 'historical',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $before = DB::table('payments')->whereIn('id', $ids)->orderBy('id')->get()->toJson();
            Artisan::call('migrate', ['--path' => 'database/migrations-v2/'.$migration, '--force' => true]);
            self::assertSame($before, DB::table('payments')->whereIn('id', $ids)->orderBy('id')->get()->toJson());
            self::assertSame(4, DB::table('payment_commercial_classifications')->whereIn('payment_id', $ids)
                ->where('classification', 'test')->where('reason', 'pay1_existing_fincode')->count());
            foreach ($ids as $id) {
                self::assertFalse((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS eligible', [$id])->eligible);
            }
            self::assertSame(0, DB::table('payment_point_grants')->count());
            self::assertSame(0, DB::table('mail_deliveries')->count());
            DB::beginTransaction();
            try {
                foreach ([null, 'credit_card', 'paypay', 'konbini', 'virtual_account', 'netridecash', 'paidy'] as $method) {
                    DB::table('payments')->where('id', $ids[0])->update(['payment_method' => $method]);
                    self::assertSame($method, DB::table('payments')->where('id', $ids[0])->value('payment_method'));
                }
                try {
                    DB::transaction(fn () => DB::table('payments')->where('id', $ids[0])->update(['payment_method' => 'unknown']));
                    self::fail('Unknown payment method must violate the database constraint.');
                } catch (\Illuminate\Database\QueryException $exception) {
                    self::assertSame('23514', $exception->getCode());
                }
            } finally {
                DB::rollBack();
            }
            self::assertSame($before, DB::table('payments')->whereIn('id', $ids)->orderBy('id')->get()->toJson());
        } finally {
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        }
    }
}
