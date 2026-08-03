<?php

namespace Tests\Unit;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2AdminPointAdjustmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (
            ! Schema::hasColumn('users', 'email_display')
            || ! Schema::hasTable('point_adjustments')
        ) {
            $this->markTestSkipped('The Admin point adjustment unit requires the V2 schema.');
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_exact_type_grants_and_deductions_use_canonical_lot_order(): void
    {
        config(['oripa.free_point_expiration_days' => 180]);
        [$user, $admin] = $this->actors();
        $service = app(V2PointService::class);
        $occurred = CarbonImmutable::parse('2026-08-03T00:00:00Z');

        $paidGrant = $service->applyAdminAdjustmentWithinTransaction(
            $user->id,
            $admin->id,
            'paid',
            'grant',
            500,
            $this->businessKey('paid-grant'),
            $occurred
        );
        $freeGrant = $service->applyAdminAdjustmentWithinTransaction(
            $user->id,
            $admin->id,
            'free',
            'grant',
            300,
            $this->businessKey('free-grant'),
            $occurred
        );
        $paidDeduct = $service->applyAdminAdjustmentWithinTransaction(
            $user->id,
            $admin->id,
            'paid',
            'deduct',
            120,
            $this->businessKey('paid-deduct'),
            $occurred->addSecond()
        );
        $freeDeduct = $service->applyAdminAdjustmentWithinTransaction(
            $user->id,
            $admin->id,
            'free',
            'deduct',
            70,
            $this->businessKey('free-deduct'),
            $occurred->addSecond()
        );

        self::assertNull($paidGrant['expire_at']);
        self::assertSame(
            '2027-01-30T00:00:00+00:00',
            $freeGrant['expire_at']?->toIso8601String()
        );
        self::assertSame(380, $paidDeduct['paid_after']);
        self::assertSame(300, $paidDeduct['free_after']);
        self::assertSame(380, $freeDeduct['paid_after']);
        self::assertSame(230, $freeDeduct['free_after']);
        self::assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 380,
            'free_balance' => 230,
            'lock_version' => 4,
        ]);
        self::assertSame(2, DB::table('point_ledger_entries')->where('entry_type', 'reverse')->count());
        self::assertSame(120, (int) DB::table('point_lots')->where('point_type', 'paid')->value('granted_amount') - (int) DB::table('point_lots')->where('point_type', 'paid')->value('remaining_amount'));
        self::assertSame(70, (int) DB::table('point_lots')->where('point_type', 'free')->value('granted_amount') - (int) DB::table('point_lots')->where('point_type', 'free')->value('remaining_amount'));
    }

    public function test_deduction_never_falls_back_to_another_point_type(): void
    {
        [$user, $admin] = $this->actors();
        $service = app(V2PointService::class);
        $occurred = CarbonImmutable::parse('2026-08-03T00:00:00Z');
        $service->applyAdminAdjustmentWithinTransaction(
            $user->id,
            $admin->id,
            'free',
            'grant',
            100,
            $this->businessKey('free-only'),
            $occurred
        );

        try {
            DB::transaction(fn () => $service->applyAdminAdjustmentWithinTransaction(
                $user->id,
                $admin->id,
                'paid',
                'deduct',
                1,
                $this->businessKey('paid-insufficient'),
                $occurred
            ));
            self::fail('Paid deduction unexpectedly consumed free points.');
        } catch (V2PointException $exception) {
            self::assertSame('INSUFFICIENT_POINT_BALANCE', $exception->getMessage());
        }

        self::assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 100,
        ]);
        self::assertSame(0, DB::table('point_ledger_entries')->where('entry_type', 'reverse')->count());
    }

    /** @return array{User, Admin} */
    private function actors(): array
    {
        $passwords = app(V2PasswordPolicy::class);
        $userEmail = 'adjustment-unit-user-'.Str::uuid7().'@example.test';
        $adminEmail = 'adjustment-unit-admin-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'email_display' => $userEmail,
            'email_normalized' => $userEmail,
            'email_verified_at' => now(),
            'password_hash' => $passwords->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
        $admin = Admin::query()->create([
            'email_display' => $adminEmail,
            'email_normalized' => $adminEmail,
            'email_verified_at' => now(),
            'password_hash' => $passwords->hash('valid admin password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);

        return [$user, $admin];
    }

    private function businessKey(string $value): string
    {
        return 'point.admin_adjustment:'.hash('sha256', $value);
    }
}
