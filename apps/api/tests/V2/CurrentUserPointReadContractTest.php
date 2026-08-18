<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CurrentUserPointReadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-15T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_wallet_returns_canonical_available_balance_expiry_buckets_and_private_cache_boundary(): void
    {
        $user = $this->user();
        $this->wallet($user, paid: 100, free: 206, paidReserved: 20);
        $sixDays = CarbonImmutable::parse('2026-08-21T00:00:00Z');
        $sixDaysLater = CarbonImmutable::parse('2026-08-21T01:00:00Z');
        $sevenDays = CarbonImmutable::parse('2026-08-22T00:00:00Z');
        $this->lot($user, 'free', 40, 10, $sixDays);
        $this->lot($user, 'free', 30, 0, $sixDays);
        $this->lot($user, 'free', 11, 0, $sixDaysLater);
        $this->lot($user, 'free', 70, 0, $sevenDays);
        $this->lot($user, 'free', 19, 0, CarbonImmutable::parse('2026-08-22T00:00:01Z'));
        $this->lot($user, 'free', 13, 0, CarbonImmutable::parse('2026-08-15T00:00:00Z'));
        $this->lot($user, 'free', 23, 23, CarbonImmutable::parse('2026-08-17T00:00:00Z'));
        Auth::guard('v2_user')->setUser($user);
        $operationCount = DB::table('point_operations')->where('user_id', $user->id)->count();
        $ledgerCount = DB::table('point_ledger_entries')->where('user_id', $user->id)->count();
        $walletState = DB::table('wallets')->where('user_id', $user->id)->first();
        $lotState = DB::table('point_lots')->where('user_id', $user->id)
            ->orderBy('id')->get([
                'remaining_amount',
                'reserved_amount',
                'expire_at',
                'legacy_no_expiry',
            ])->all();

        $response = $this->getJson('/api/v2/me/wallet')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('paid_points', 80)
            ->assertJsonPath('free_points', 160)
            ->assertJsonPath('total_points', 240)
            ->assertJsonPath('as_of', '2026-08-15T00:00:00Z')
            ->assertJsonPath('expiring_within_7_days', [
                ['expires_at' => '2026-08-21T00:00:00Z', 'amount' => 60],
                ['expires_at' => '2026-08-21T01:00:00Z', 'amount' => 11],
                ['expires_at' => '2026-08-22T00:00:00Z', 'amount' => 70],
            ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame($operationCount, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame($ledgerCount, DB::table('point_ledger_entries')->where('user_id', $user->id)->count());
        self::assertEquals($walletState, DB::table('wallets')->where('user_id', $user->id)->first());
        self::assertEquals(
            $lotState,
            DB::table('point_lots')->where('user_id', $user->id)
                ->orderBy('id')->get([
                    'remaining_amount',
                    'reserved_amount',
                    'expire_at',
                    'legacy_no_expiry',
                ])->all()
        );
    }

    public function test_wallet_preserves_legacy_no_expiry_balance_without_expiry_bucket(): void
    {
        $user = $this->user();
        $this->wallet($user, paid: 50, free: 30);
        DB::statement('ALTER TABLE point_lots DISABLE TRIGGER point_lots_new_expiry_guard');
        try {
            $this->lot($user, 'paid', 50, 0, null, true);
        } finally {
            DB::statement('ALTER TABLE point_lots ENABLE TRIGGER point_lots_new_expiry_guard');
        }
        $this->lot($user, 'free', 30, 0, CarbonImmutable::now()->addDays(3));
        Auth::guard('v2_user')->setUser($user);

        $this->getJson('/api/v2/me/wallet')
            ->assertOk()
            ->assertJsonPath('paid_points', 50)
            ->assertJsonPath('free_points', 30)
            ->assertJsonPath('total_points', 80)
            ->assertJsonPath('as_of', '2026-08-15T00:00:00Z')
            ->assertJsonPath('expiring_within_7_days', [
                ['expires_at' => '2026-08-18T00:00:00Z', 'amount' => 30],
            ]);
    }

    public function test_wallet_without_row_is_zero_and_read_does_not_create_domain_data(): void
    {
        $user = $this->user();
        Auth::guard('v2_user')->setUser($user);

        self::assertSame(0, DB::table('wallets')->where('user_id', $user->id)->count());
        $this->getJson('/api/v2/me/wallet')->assertExactJson([
            'paid_points' => 0,
            'free_points' => 0,
            'total_points' => 0,
            'as_of' => '2026-08-15T00:00:00Z',
            'expiring_within_7_days' => [],
        ]);
        self::assertSame(0, DB::table('wallets')->where('user_id', $user->id)->count());
    }

    public function test_history_is_operation_scoped_presented_and_stably_cursor_paginated(): void
    {
        $user = $this->user();
        $walletId = $this->wallet($user);
        $older = CarbonImmutable::parse('2026-08-14T23:59:00Z');
        $sameTime = CarbonImmutable::parse('2026-08-15T00:00:00Z');
        $purchase = $this->operation($user, $walletId, 'paid_grant', 'payment', 1000, $older);
        $this->operation($user, $walletId, 'spend', 'draw', [-200, -100], $sameTime);
        $exchange = $this->operation(
            $user,
            $walletId,
            'free_grant',
            'prize_exchange',
            50,
            $sameTime
        );
        $adjustment = $this->operation(
            $user,
            $walletId,
            'adjustment_deduct',
            'admin_adjustment',
            -10,
            $sameTime
        );
        Auth::guard('v2_user')->setUser($user);

        $first = $this->getJson('/api/v2/me/point-ledgers?limit=2')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $adjustment)
            ->assertJsonPath('items.0.amount_delta', -10)
            ->assertJsonPath('items.0.reason.label', 'ポイント調整')
            ->assertJsonPath('items.1.id', $exchange)
            ->assertJsonPath('items.1.reason.label', '景品のポイント交換');
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);

        $second = $this->getJson('/api/v2/me/point-ledgers?limit=2&cursor='.$cursor)
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.amount_delta', -300)
            ->assertJsonPath('items.0.reason.label', 'ガチャ利用')
            ->assertJsonPath('items.1.id', $purchase)
            ->assertJsonPath('items.1.reason.label', 'ポイント購入')
            ->assertJsonPath('next_cursor', null);
        $serialized = (string) $second->getContent();
        foreach ([
            'wallet_id',
            'point_lot_id',
            'point_operation_id',
            'source_type',
            'operation_type',
            'business_key',
            'actor_type',
            'internal_id',
        ] as $internalField) {
            self::assertStringNotContainsString($internalField, $serialized);
        }
    }

    public function test_history_empty_invalid_cursor_and_owner_boundary_are_canonical(): void
    {
        $user = $this->user();
        $other = $this->user();
        $otherWalletId = $this->wallet($other);
        $otherOperation = $this->operation(
            $other,
            $otherWalletId,
            'free_grant',
            'referral',
            100,
            now()
        );
        Auth::guard('v2_user')->setUser($user);

        $this->getJson('/api/v2/me/point-ledgers')
            ->assertOk()
            ->assertExactJson(['items' => [], 'next_cursor' => null]);
        $this->getJson('/api/v2/me/point-ledgers?cursor=invalid-cursor')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_CURSOR');
        $otherCursor = rtrim(strtr(base64_encode($otherOperation), '+/', '-_'), '=');
        $this->getJson('/api/v2/me/point-ledgers?cursor='.$otherCursor)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_CURSOR');
        $this->getJson('/api/v2/me/point-ledgers?limit=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_PAGINATION');
    }

    public function test_reads_require_user_session_and_problem_is_private(): void
    {
        foreach (['/api/v2/me/wallet', '/api/v2/me/point-ledgers'] as $path) {
            $response = $this->getJson($path)
                ->assertUnauthorized()
                ->assertHeader('Vary', 'Cookie')
                ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
            $cacheControl = (string) $response->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
        }
    }

    private function user(): User
    {
        $email = 'current-point-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic point reader',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function wallet(
        User $user,
        int $paid = 0,
        int $free = 0,
        int $paidReserved = 0,
        int $freeReserved = 0
    ): int {
        return DB::table('wallets')->insertGetId([
            'user_id' => $user->id,
            'paid_balance' => $paid,
            'free_balance' => $free,
            'paid_reserved_balance' => $paidReserved,
            'free_reserved_balance' => $freeReserved,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param int|list<int> $deltas
     */
    private function operation(
        User $user,
        int $walletId,
        string $operationType,
        string $sourceType,
        int|array $deltas,
        mixed $occurredAt
    ): string {
        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $publicId = (string) Str::uuid7();
        $operationId = DB::table('point_operations')->insertGetId([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'operation_type' => $operationType,
            'business_key' => 'point.read.fixture:'.Str::uuid7(),
            'source_type' => $sourceType,
            'source_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'is_qa' => false,
            'qa_draw_execution_id' => null,
            'occurred_at' => $occurred,
            'business_date' => $occurred->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => $occurred,
        ]);
        foreach ((array) $deltas as $index => $delta) {
            DB::table('point_ledger_entries')->insert([
                'point_operation_id' => $operationId,
                'sequence_no' => $index + 1,
                'user_id' => $user->id,
                'wallet_id' => $walletId,
                'point_lot_id' => null,
                'point_type' => $operationType === 'paid_grant' || $index > 0
                    ? 'paid'
                    : 'free',
                'entry_type' => $delta > 0 ? 'grant' : 'spend',
                'amount_delta' => $delta,
                'wallet_balance_after' => 0,
                'lot_remaining_after' => null,
                'occurred_at' => $occurred,
                'business_date' => $occurred->setTimezone('Asia/Tokyo')->toDateString(),
                'created_at' => $occurred,
            ]);
        }

        return $publicId;
    }

    private function lot(
        User $user,
        string $pointType,
        int $remainingAmount,
        int $reservedAmount,
        ?CarbonImmutable $expiresAt,
        bool $legacyNoExpiry = false
    ): void {
        $operationId = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'operation_type' => $pointType.'_grant',
            'business_key' => 'point.read.lot:'.Str::uuid7(),
            'source_type' => 'admin_adjustment',
            'source_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'is_qa' => false,
            'qa_draw_execution_id' => null,
            'occurred_at' => now(),
            'business_date' => now()->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => now(),
        ]);
        DB::table('point_lots')->insert([
            'user_id' => $user->id,
            'grant_operation_id' => $operationId,
            'point_type' => $pointType,
            'granted_amount' => $remainingAmount,
            'remaining_amount' => $remainingAmount,
            'reserved_amount' => $reservedAmount,
            'granted_at' => now(),
            'expire_at' => $expiresAt?->toIso8601String(),
            'legacy_no_expiry' => $legacyNoExpiry,
        ]);
    }
}
