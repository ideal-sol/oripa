<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogReadService;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrizeShippingVerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_prize_shipping.address_hmac_key' => 'base64:'.
                base64_encode(str_repeat('p', 32)),
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_auto_exchange_flag_deadline_snapshot_expiry_and_replay(): void
    {
        [$user, $ids] = $this->fixture(1);
        $service = app(V2PrizeShippingService::class);
        $prize = DB::table('user_prizes')->where('public_id', $ids[0])->first();
        $deadline = CarbonImmutable::parse($prize->storage_expires_at);
        self::assertTrue($deadline->equalTo(CarbonImmutable::parse($prize->acquired_at)->addDays(60)));
        CarbonImmutable::setTestNow($deadline);
        self::assertSame(['processed' => 0, 'failed' => 0], $service->autoExchangeExpired());
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        CarbonImmutable::setTestNow($deadline->subSecond());
        self::assertSame(0, $service->autoExchangeExpired()['processed']);
        CarbonImmutable::setTestNow($deadline);
        $before = (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance');
        self::assertSame(['processed' => 1, 'failed' => 0], $service->autoExchangeExpired());
        self::assertSame('converted', DB::table('user_prizes')->where('id', $prize->id)->value('status'));
        self::assertSame($before + (int) $prize->exchange_point_snapshot, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        $request = DB::table('prize_exchange_requests')->where('user_id', $user->id)->sole();
        $operation = DB::table('point_operations')->where('id', $request->point_operation_id)->sole();
        self::assertSame('system', $operation->actor_type);
        $lot = DB::table('point_lots')->where('grant_operation_id', $operation->id)->sole();
        self::assertSame('free', $lot->point_type);
        self::assertTrue(CarbonImmutable::parse($lot->expire_at)->equalTo($deadline->addDays(180)));
        self::assertSame(0, $service->autoExchangeExpired()['processed']);
        self::assertTrue(CarbonImmutable::parse(DB::table('user_prizes')->where('id', $prize->id)->value('storage_expires_at'))->equalTo($deadline));
    }

    public function test_auto_exchange_seven_days_batch_and_disabled_command(): void
    {
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        [$user, $ids] = $this->fixture(5);
        $prize = DB::table('user_prizes')->where('public_id', $ids[0])->first();
        $deadline = CarbonImmutable::parse($prize->storage_expires_at);
        self::assertTrue($deadline->equalTo(CarbonImmutable::parse($prize->acquired_at)->addDays(7)));
        CarbonImmutable::setTestNow($deadline);
        self::assertSame(1, app(V2PrizeShippingService::class)->autoExchangeExpired(1)['processed']);
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => false]);
        $this->artisan('v2:prizes:auto-exchange-expired', ['--limit' => 1])->assertSuccessful();
        self::assertSame(1, DB::table('prize_exchange_requests')->where('user_id', $user->id)->count());
        $this->artisan('v2:prizes:auto-exchange-expired', ['--limit' => 0])->assertExitCode(2);
    }

    public function test_auto_exchange_zero_value_expires_without_point_operation(): void
    {
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        [$user, $ids] = $this->fixture(1, 0, false, true);
        $prize = DB::table('user_prizes')->where('public_id', $ids[0])->first();
        CarbonImmutable::setTestNow(CarbonImmutable::parse($prize->storage_expires_at));
        $before = DB::table('point_operations')->count();
        self::assertSame(1, app(V2PrizeShippingService::class)->autoExchangeExpired()['processed']);
        self::assertSame('expired', DB::table('user_prizes')->where('id', $prize->id)->value('status'));
        self::assertSame($before, DB::table('point_operations')->count());
    }

    public function test_auto_exchange_skips_suspended_members(): void
    {
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        [$user, $ids] = $this->fixture(1);
        $prize = DB::table('user_prizes')->where('public_id', $ids[0])->first();
        CarbonImmutable::setTestNow(CarbonImmutable::parse($prize->storage_expires_at));
        DB::table('users')->where('id', $user->id)->update(['state' => 'suspended']);
        self::assertSame(0, app(V2PrizeShippingService::class)->autoExchangeExpired()['processed']);
    }

    public function test_auto_exchange_failure_rolls_back_and_rotates_then_retries(): void
    {
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        [$user, $ids] = $this->fixture(5);
        $deadline = DB::table('user_prizes')->where('public_id', $ids[0])->value('storage_expires_at');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($deadline));
        $before = DB::table('wallets')->where('user_id', $user->id)->value('free_balance');
        $operations = DB::table('point_operations')->count();
        $key = config('v2_audit.hmac_keys.v1');
        config(['v2_audit.hmac_keys.v1' => 'base64:invalid']);
        $service = app(V2PrizeShippingService::class);
        self::assertSame(['processed' => 0, 'failed' => 1], $service->autoExchangeExpired(1));
        self::assertSame($before, DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($operations, DB::table('point_operations')->count());
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, DB::table('prize_exchange_request_items')->count());
        self::assertSame(5, DB::table('user_prizes')->where('user_id', $user->id)->where('status', 'stored')->count());
        $failedId = (int) DB::table('user_prizes')->where('user_id', $user->id)->min('id');
        config(['v2_audit.hmac_keys.v1' => $key]);
        self::assertSame(1, $service->autoExchangeExpired(1)['processed']);
        self::assertSame('stored', DB::table('user_prizes')->where('id', $failedId)->value('status'));
        self::assertSame(3, $service->autoExchangeExpired()['processed']);
        self::assertSame(1, $service->autoExchangeExpired()['processed']);
        self::assertSame(0, $service->autoExchangeExpired()['processed']);
        self::assertSame(5, DB::table('prize_exchange_requests')->count());
    }

    public function test_auto_exchange_restricted_hold_and_closed_member_boundaries(): void
    {
        config(['v2_prize_shipping.seven_day_auto_convert_enabled' => true]);
        [$user, $ids] = $this->fixture(5);
        $this->placePaymentHold($user, $ids[0]);
        DB::table('users')->where('id', $user->id)->update(['state' => 'restricted']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('user_prizes')->where('public_id', $ids[0])->value('storage_expires_at')));
        $service = app(V2PrizeShippingService::class);
        self::assertSame(4, $service->autoExchangeExpired()['processed']);
        self::assertSame('stored', DB::table('user_prizes')->where('public_id', $ids[0])->value('status'));
        app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($user->refresh(), 'admin', 'normal', (string) Str::uuid7());
        self::assertSame(0, $service->autoExchangeExpired()['processed']);
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
    }

    public function test_schema_uses_snapshots_encrypted_pii_and_immutable_histories(): void
    {
        foreach ([
            'user_prize_status_histories',
            'prize_exchange_requests',
            'prize_exchange_request_items',
            'shipping_addresses',
            'shipping_requests',
            'shipping_request_items',
            'shipping_request_status_histories',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }
        self::assertTrue(Schema::hasColumn('user_prizes', 'exchange_point_snapshot'));
        self::assertTrue(Schema::hasColumn('user_prizes', 'storage_expires_at'));
        self::assertFalse(Schema::hasColumn('shipping_addresses', 'recipient_name'));
        self::assertFalse(Schema::hasColumn('shipping_requests', 'tracking_number'));

        [$user, $prizes] = $this->fixture(1);
        $row = DB::table('user_prizes')->where('public_id', $prizes[0])->first();
        self::assertSame(8000, (int) $row->exchange_point_snapshot);
        self::assertFalse((bool) $row->shipping_only_snapshot);
        self::assertSame(
            '2026-09-28 09:00:00+09:00',
            CarbonImmutable::parse($row->storage_expires_at)->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:sP')
        );

        DB::table('user_prize_status_histories')->insert($this->historyRow($row->id));
        try {
            DB::table('user_prize_status_histories')->where('user_prize_id', $row->id)
                ->update(['reason_code' => 'tampered']);
            self::fail('User Prize history update must fail.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        self::assertSame($user->id, (int) $row->user_id);
    }

    public function test_prize_list_is_owner_scoped_cursor_paginated_and_public_safe(): void
    {
        [$user, $prizes] = $this->fixture(5);
        $other = $this->user();
        $service = app(V2PrizeShippingService::class);
        $page = $service->prizes($user, null, 2);

        self::assertCount(2, $page['items']);
        self::assertNotNull($page['next_cursor']);
        self::assertCount(2, $service->prizes($user, $page['next_cursor'], 2)['items']);
        self::assertSame([], $service->prizes($other, null, 20)['items']);
        self::assertSame($prizes[4], $page['items'][0]['id']);
        self::assertSame(
            '0198a001-0000-7000-8000-000000000009',
            $page['items'][0]['presentation']['prize_id']
        );
        self::assertSame('Fixture S景品', $page['items'][0]['presentation']['name']);
        self::assertSame('Sランク', $page['items'][0]['presentation']['rank']['name']);
        self::assertArrayNotHasKey('code', $page['items'][0]['presentation']['rank']);
        self::assertTrue($page['items'][0]['allowed_actions']['shipping']['allowed']);
        self::assertTrue($page['items'][0]['allowed_actions']['point_exchange']['allowed']);
        self::assertTrue($page['items'][0]['allowed_actions']['selection']['allowed']);
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal_id', $serialized);
        self::assertStringNotContainsString('exchange_point_snapshot', $serialized);
        self::assertStringNotContainsString('individual_ppm', $serialized);
        self::assertStringNotContainsString('cost_price', $serialized);
    }

    public function test_unverified_user_cannot_use_any_address_operation_or_create_shipping(): void
    {
        [$user, $prizes] = $this->fixture(1);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $shipping = $service->createShippingRequest(
            $user,
            $address['id'],
            $prizes,
            'sms-gate-shipping-'.Str::uuid(),
            (string) Str::uuid7()
        );
        UserPhoneNumber::query()->where('user_id', $user->getKey())->update([
            'revoked_at' => now(),
        ]);

        $operations = [
            fn () => $service->exchange($user, $prizes, 'sms-gate-exchange-denied', (string) Str::uuid7()),
            fn () => $service->addresses($user),
            fn () => $service->addressDetail($user, $address['id'], (string) Str::uuid7()),
            fn () => $service->createAddress($user, $this->address(), (string) Str::uuid7()),
            fn () => $service->updateAddress(
                $user,
                $address['id'],
                $this->address(),
                (string) Str::uuid7()
            ),
            fn () => $service->deleteAddress($user, $address['id'], (string) Str::uuid7()),
            fn () => $service->createShippingRequest(
                $user,
                $address['id'],
                $prizes,
                'sms-gate-shipping-retry-'.Str::uuid(),
                (string) Str::uuid7()
            ),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('SMS verification must gate the operation.');
            } catch (V2PrizeShippingException $exception) {
                self::assertSame('SMS_VERIFICATION_REQUIRED', $exception->errorCode);
                self::assertSame(403, $exception->status);
            }
        }

        self::assertSame($shipping['id'], $service->shippingRequests($user, null, 20)['items'][0]['id']);
        self::assertSame($shipping['id'], $service->shippingDetail(
            $user,
            $shipping['id'],
            (string) Str::uuid7()
        )['id']);
    }

    public function test_prize_presentation_actions_are_backend_authoritative_and_private(): void
    {
        [$user, $prizes] = $this->fixture(10);
        CarbonImmutable::setTestNow('2026-09-01T00:00:00Z');
        DB::table('user_prizes')->where('public_id', $prizes[2])->update([
            'status' => 'exchange_processing',
            'updated_at' => now(),
        ]);
        DB::table('user_prizes')->where('public_id', $prizes[3])->update([
            'status' => 'delivered',
            'terminal_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_prizes')->where('public_id', $prizes[4])->update([
            'status' => 'expired',
            'terminal_at' => now(),
            'updated_at' => now(),
        ]);
        $this->placePaymentHold($user, $prizes[6]);

        $service = app(V2PrizeShippingService::class);
        $both = $service->prizeDetail($user, $prizes[0]);
        self::assertSame(
            ['shipping' => true, 'point_exchange' => true, 'selection' => true],
            collect($both['allowed_actions'])
                ->map(fn (array $action): bool => $action['allowed'])
                ->all()
        );
        self::assertNull($both['allowed_actions']['shipping']['unavailable_reason']);
        self::assertArrayNotHasKey('internal_id', $both['presentation']);

        foreach ([
            $prizes[2] => 'status_not_actionable',
            $prizes[3] => 'status_not_actionable',
            $prizes[4] => 'status_not_actionable',
            $prizes[6] => 'payment_hold',
        ] as $publicId => $reason) {
            $item = $service->prizeDetail($user, $publicId);
            self::assertFalse($item['allowed_actions']['shipping']['allowed']);
            self::assertFalse($item['allowed_actions']['point_exchange']['allowed']);
            self::assertFalse($item['allowed_actions']['selection']['allowed']);
            self::assertSame(
                $reason,
                $item['allowed_actions']['selection']['unavailable_reason']
            );
        }

        Auth::guard('v2_user')->setUser($user);
        $response = $this->getJson('/api/v2/me/prizes/'.$prizes[0])
            ->assertOk()
            ->assertJsonPath('presentation.name', 'Fixture S景品')
            ->assertJsonPath('allowed_actions.point_exchange.allowed', true);
        self::assertStringContainsString(
            'private',
            (string) $response->headers->get('Cache-Control')
        );
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        self::assertSame('Cookie', $response->headers->get('Vary'));
        $this->getJson('/api/v2/me/prizes/'.Str::uuid7())
            ->assertNotFound()
            ->assertJsonPath('code', 'USER_PRIZE_NOT_FOUND');

        Auth::forgetGuards();
        $this->getJson('/api/v2/me/prizes')->assertUnauthorized();
    }

    public function test_zero_exchange_points_remain_shipping_candidates_but_not_exchangeable(): void
    {
        [$user, $prizes] = $this->fixture(1, 0);
        $item = app(V2PrizeShippingService::class)->prizeDetail($user, $prizes[0]);

        self::assertTrue($item['allowed_actions']['shipping']['allowed']);
        self::assertFalse($item['allowed_actions']['point_exchange']['allowed']);
        self::assertSame(
            'exchange_points_unavailable',
            $item['allowed_actions']['point_exchange']['unavailable_reason']
        );
        self::assertTrue($item['allowed_actions']['selection']['allowed']);
    }

    public function test_shipping_only_snapshot_blocks_direct_and_mixed_exchange_without_side_effects(): void
    {
        [$user, $prizes] = $this->fixture(1, 8000, true);
        $service = app(V2PrizeShippingService::class);
        $owned = DB::table('user_prizes')->where('public_id', $prizes[0])->first();
        self::assertTrue((bool) $owned->shipping_only_snapshot);
        DB::table('catalog_prizes')->update(['shipping_only' => false, 'revision' => DB::raw('revision + 1')]);
        $catalog = app(V2CatalogReadService::class)->getByPublicId(DB::table('catalog_gachas')->value('public_id'));
        self::assertTrue($catalog['prizes'][0]['shipping_only']);
        $item = $service->prizeDetail($user, $prizes[0]);
        self::assertTrue($item['shipping_only']);
        self::assertTrue($item['allowed_actions']['shipping']['allowed']);
        self::assertTrue($item['allowed_actions']['selection']['allowed']);
        self::assertSame(['allowed' => false, 'unavailable_reason' => 'shipping_only'], $item['allowed_actions']['point_exchange']);
        self::assertSame(8000, $item['exchange_points']);
        self::assertSame(60, (int) CarbonImmutable::parse($owned->acquired_at)->diffInDays(CarbonImmutable::parse($owned->storage_expires_at)));
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(
            static fn (int $minimum, int $maximum): int => $maximum
        ));
        app(V2DrawService::class)->create($user, DB::table('catalog_gachas')->value('public_id'), 1, 'shipping-only-normal-draw', (string) Str::uuid7());
        $normal = DB::table('user_prizes')->where('user_id', $user->id)->where('shipping_only_snapshot', false)->firstOrFail();
        $before = DB::table('wallets')->where('user_id', $user->id)->value('free_balance');
        $this->withoutMiddleware();
        Auth::guard('v2_user')->setUser($user);
        foreach ([[$prizes[0]], [$normal->public_id, $prizes[0]]] as $ids) {
            $this->postJson('/api/v2/me/prizes/exchange', ['prize_ids' => $ids], [
                'Idempotency-Key' => (string) Str::uuid(),
            ])->assertStatus(409)->assertJsonPath('code', 'PRIZE_NOT_EXCHANGEABLE');
        }
        self::assertSame($before, DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'prize_exchange')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'prize.exchange.completed')->count());
        self::assertSame(0, DB::table('user_prizes')->where('status', '!=', 'stored')->count());
        self::assertSame(0, DB::table('user_prize_status_histories')->whereIn('to_status', ['exchange_processing', 'converted'])->count());
        self::assertFalse($service->prizeDetail($user, $normal->public_id)['shipping_only']);
        $service->exchange($user, [$normal->public_id], 'normal-only-after-rejection', (string) Str::uuid7());
        self::assertDatabaseHas('user_prizes', ['id' => $normal->id, 'status' => 'converted']);
    }

    public function test_shipping_only_expiration_is_boundary_exact_idempotent_and_preserves_hold_and_inventory(): void
    {
        [$user, $prizes] = $this->fixture(5, 8000, true);
        $service = app(V2PrizeShippingService::class);
        $this->placePaymentHold($user, $prizes[1]);
        DB::table('user_prizes')->where('public_id', $prizes[2])->update(['status' => 'hold']);
        DB::table('user_prizes')->where('public_id', $prizes[3])->update(['status' => 'shipping_requested']);
        $holds = DB::table('payment_adjustment_prize_actions')->get()->toJson();
        $inventory = DB::table('prize_inventories')->orderBy('id')->get()->toJson();
        $points = DB::table('wallets')->where('user_id', $user->id)->first();
        $expires = CarbonImmutable::parse(DB::table('user_prizes')->where('public_id', $prizes[0])->value('storage_expires_at'));
        CarbonImmutable::setTestNow($expires->subSecond());
        self::assertSame(0, $service->expireShippingOnly());
        CarbonImmutable::setTestNow($expires);
        $this->artisan('v2:prizes:expire-shipping-only', ['--limit' => 1])->expectsOutput('Expired shipping-only prizes: 1')->assertSuccessful();
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[0], 'status' => 'expired']);
        self::assertNotNull(DB::table('user_prizes')->where('public_id', $prizes[0])->value('terminal_at'));
        CarbonImmutable::setTestNow($expires->addSecond());
        self::assertSame(1, $service->expireShippingOnly());
        self::assertSame(0, $service->expireShippingOnly());
        self::assertSame(2, DB::table('audit_logs')->where('action_code', 'prize.shipping_only_expired')->count());
        self::assertSame(2, DB::table('user_prize_status_histories')->where('to_status', 'expired')->count());
        self::assertSame($holds, DB::table('payment_adjustment_prize_actions')->get()->toJson());
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[1], 'status' => 'stored']);
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[2], 'status' => 'hold']);
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[3], 'status' => 'shipping_requested']);
        foreach ($service->prizeDetail($user, $prizes[1])['allowed_actions'] as $action) {
            self::assertFalse($action['allowed']);
        }
        DB::table('user_prizes')->where('public_id', $prizes[2])->update(['status' => 'stored']);
        self::assertSame(1, $service->expireShippingOnly());
        self::assertEquals($points, DB::table('wallets')->where('user_id', $user->id)->first());
        self::assertSame($inventory, DB::table('prize_inventories')->orderBy('id')->get()->toJson());
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'prize_exchange')->count());
        self::assertSame(0, DB::table('shipping_requests')->count());
    }

    public function test_shipping_only_snapshot_is_immutable(): void
    {
        [, $prizes] = $this->fixture(1, 8000, true);
        $this->expectException(QueryException::class);
        DB::table('user_prizes')->where('public_id', $prizes[0])->update(['shipping_only_snapshot' => false]);
    }

    public function test_shipping_only_still_requires_verified_phone_and_address_then_allows_shipping(): void
    {
        [$user, $prizes] = $this->fixture(1, 8000, true);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        try {
            $service->createShippingRequest($user, (string) Str::uuid7(), $prizes, 'shipping-only-invalid-address', (string) Str::uuid7());
            self::fail('Shipping-only prizes must require an owned address.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('SHIPPING_ADDRESS_NOT_FOUND', $exception->errorCode);
        }
        UserPhoneNumber::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);
        try {
            $service->createShippingRequest($user, $address['id'], $prizes, 'shipping-only-invalid-phone', (string) Str::uuid7());
            self::fail('Shipping-only prizes must require SMS verification.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('SMS_VERIFICATION_REQUIRED', $exception->errorCode);
        }
        UserPhoneNumber::query()->where('user_id', $user->id)->update(['revoked_at' => null]);
        $response = $service->createShippingRequest($user, $address['id'], $prizes, 'shipping-only-delivery', (string) Str::uuid7());
        self::assertSame('requested', $response['status']);
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[0], 'status' => 'shipping_requested']);
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, $service->expireShippingOnly());
    }

    public function test_shipping_only_cannot_enter_exchange_state_even_without_application_guard(): void
    {
        [, $prizes] = $this->fixture(1, 8000, true);
        $this->expectException(QueryException::class);
        DB::table('user_prizes')->where('public_id', $prizes[0])->update(['status' => 'exchange_processing']);
    }

    public function test_storage_expiry_boundary_disallows_all_actions(): void
    {
        [$user, $prizes] = $this->fixture(1);
        $expiresAt = DB::table('user_prizes')
            ->where('public_id', $prizes[0])
            ->value('storage_expires_at');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($expiresAt));

        self::assertSame(0, app(V2PrizeShippingService::class)->expireShippingOnly());
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[0], 'status' => 'stored']);

        $item = app(V2PrizeShippingService::class)->prizeDetail($user, $prizes[0]);
        foreach ($item['allowed_actions'] as $action) {
            self::assertFalse($action['allowed']);
            self::assertSame('storage_expired', $action['unavailable_reason']);
        }
    }

    public function test_bulk_exchange_grants_snapshot_free_points_and_replays_once(): void
    {
        [$user, $prizes] = $this->fixture(3);
        $service = app(V2PrizeShippingService::class);
        $before = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $first = $service->exchange(
            $user,
            [$prizes[0], $prizes[1]],
            'exchange-idempotency-0001',
            (string) Str::uuid7()
        );
        $replay = $service->exchange(
            $user,
            [$prizes[1], $prizes[0]],
            'exchange-idempotency-0001',
            (string) Str::uuid7()
        );

        self::assertSame(16_000, $first['exchange_point_total']);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame(
            $before + 16_000,
            (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance')
        );
        self::assertSame(2, DB::table('user_prizes')->where('status', 'converted')->count());
        self::assertSame(1, DB::table('point_operations')
            ->where('source_type', 'prize_exchange')->count());
        self::assertSame(1, DB::table('point_lots')
            ->where('granted_amount', 16_000)->count());
        self::assertSame(1, DB::table('point_ledger_entries')
            ->where('amount_delta', 16_000)->count());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'prize.exchanged']);
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'prize.exchange.completed',
        ]);

        try {
            $service->exchange(
                $user,
                [$prizes[2]],
                'exchange-idempotency-0001',
                (string) Str::uuid7()
            );
            self::fail('Idempotency conflict must fail.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
    }

    public function test_exchange_rolls_back_all_items_when_one_prize_is_invalid(): void
    {
        [$user, $prizes] = $this->fixture(2);
        DB::table('user_prizes')->where('public_id', $prizes[1])->update([
            'status' => 'hold',
            'updated_at' => now(),
        ]);
        $before = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');

        try {
            app(V2PrizeShippingService::class)->exchange(
                $user,
                $prizes,
                'exchange-idempotency-rollback',
                (string) Str::uuid7()
            );
            self::fail('Mixed valid and invalid Prize exchange must fail.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('PRIZE_NOT_EXCHANGEABLE', $exception->errorCode);
        }

        self::assertSame($before, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(4, DB::table('user_prizes')->where('status', 'stored')->count());
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, DB::table('point_operations')
            ->where('source_type', 'prize_exchange')->count());
    }

    public function test_address_is_encrypted_owner_scoped_masked_and_snapshot_is_stable(): void
    {
        [$user, $prizes] = $this->fixture(1);
        $other = $this->user();
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $stored = DB::table('shipping_addresses')->where('public_id', $address['id'])->first();

        self::assertNotSame($this->address()['recipient_name'], $stored->recipient_name_ciphertext);
        self::assertNotSame($this->address()['phone_number'], $stored->phone_number_ciphertext);
        self::assertArrayNotHasKey('city', $service->addresses($user)['items'][0]);
        self::assertSame(
            $this->address()['recipient_name'],
            $service->addressDetail($user, $address['id'], (string) Str::uuid7())['recipient_name']
        );
        try {
            $service->addressDetail($other, $address['id'], (string) Str::uuid7());
            self::fail('Other User Address must not be visible.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame(404, $exception->status);
        }

        $shipping = $service->createShippingRequest(
            $user,
            $address['id'],
            $prizes,
            'shipping-idempotency-0001',
            (string) Str::uuid7()
        );
        $changed = [...$this->address(), 'city' => '変更後市'];
        $service->updateAddress($user, $address['id'], $changed, (string) Str::uuid7());
        $detail = $service->shippingDetail($user, $shipping['id'], 'req-shipping-detail');
        self::assertSame($this->address()['city'], $detail['shipping_address']['city']);
        $service->deleteAddress($user, $address['id'], (string) Str::uuid7());
        self::assertSame(
            $this->address()['city'],
            $service->shippingDetail(
                $user,
                $shipping['id'],
                'req-shipping-detail-after-delete'
            )['shipping_address']['city']
        );
        $audit = DB::table('audit_logs')->pluck('metadata_redacted')->implode(' ');
        self::assertStringNotContainsString($this->address()['recipient_name'], $audit);
        self::assertStringNotContainsString($this->address()['phone_number'], $audit);
    }

    public function test_address_create_is_idempotent_and_rejects_key_reuse(): void
    {
        [$user] = $this->fixture(1);
        $service = app(V2PrizeShippingService::class);
        $key = 'shipping-address-create-0001';
        $first = $service->createAddressIdempotent(
            $user,
            $this->address(),
            $key,
            (string) Str::uuid7()
        );
        $replay = $service->createAddressIdempotent(
            $user,
            $this->address(),
            $key,
            (string) Str::uuid7()
        );

        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['data']['id'], $replay['data']['id']);
        self::assertSame(1, DB::table('shipping_addresses')->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'shipping.address_created')->count());
        $idempotency = DB::table('idempotency_records')
            ->where('scope', 'shipping.address.create')
            ->first();
        self::assertNull($idempotency->response_data);
        self::assertSame($first['data']['id'], $idempotency->resource_public_id);

        try {
            $service->createAddressIdempotent(
                $user,
                [...$this->address(), 'city' => '別の検証市'],
                $key,
                (string) Str::uuid7()
            );
            self::fail('Reusing an Address Idempotency Key must fail closed.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
        self::assertSame(1, DB::table('shipping_addresses')->count());
    }

    public function test_address_create_endpoint_replays_idempotency_and_keeps_legacy_request(): void
    {
        [$user] = $this->fixture(1);
        Auth::guard('v2_user')->setUser($user);
        $this->withoutMiddleware();
        $key = 'shipping-address-http-key-0001';

        $first = $this->postJson('/api/v2/me/shipping-addresses', $this->address(), [
            'Idempotency-Key' => $key,
        ])->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->postJson('/api/v2/me/shipping-addresses', $this->address(), [
            'Idempotency-Key' => $key,
        ])->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('id', $first->json('id'));
        $this->postJson(
            '/api/v2/me/shipping-addresses',
            [...$this->address(), 'city' => '別の検証市'],
            ['Idempotency-Key' => $key]
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->postJson(
            '/api/v2/me/shipping-addresses',
            [...$this->address(), 'city' => 'Legacy Client City']
        )->assertCreated();

        self::assertSame(2, DB::table('shipping_addresses')->count());
    }

    public function test_address_mutations_rollback_when_pii_access_audit_fails(): void
    {
        [$user] = $this->fixture(1);
        $service = app(V2PrizeShippingService::class);
        $validKey = config('v2_audit.hmac_keys.v1');

        $failed = false;
        config(['v2_audit.hmac_keys.v1' => 'base64:invalid']);
        try {
            $service->createAddress($user, $this->address(), (string) Str::uuid7());
        } catch (\RuntimeException) {
            $failed = true;
        } finally {
            config(['v2_audit.hmac_keys.v1' => $validKey]);
        }
        self::assertTrue($failed, 'Address creation must fail closed when Audit fails.');
        self::assertSame(0, DB::table('shipping_addresses')->count());

        $address = $service->createAddress(
            $user,
            $this->address(),
            (string) Str::uuid7()
        );
        $failed = false;
        config(['v2_audit.hmac_keys.v1' => 'base64:invalid']);
        try {
            $service->updateAddress(
                $user,
                $address['id'],
                [...$this->address(), 'city' => '監査失敗市'],
                (string) Str::uuid7()
            );
        } catch (\RuntimeException) {
            $failed = true;
        } finally {
            config(['v2_audit.hmac_keys.v1' => $validKey]);
        }
        self::assertTrue($failed, 'Address update must fail closed when Audit fails.');
        self::assertSame(1, DB::table('shipping_addresses')->count());
        self::assertSame(
            $this->address()['city'],
            $service->addressDetail(
                $user,
                $address['id'],
                (string) Str::uuid7()
            )['city']
        );

        $failed = false;
        config(['v2_audit.hmac_keys.v1' => 'base64:invalid']);
        try {
            $service->deleteAddress($user, $address['id'], (string) Str::uuid7());
        } catch (\RuntimeException) {
            $failed = true;
        } finally {
            config(['v2_audit.hmac_keys.v1' => $validKey]);
        }
        self::assertTrue($failed, 'Address deletion must fail closed when Audit fails.');
        self::assertSame(
            0,
            DB::table('shipping_addresses')->whereNotNull('deleted_at')->count()
        );
    }

    public function test_shipping_request_is_atomic_replay_safe_and_tracks_admin_transitions(): void
    {
        [$user, $prizes] = $this->fixture(3);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $first = $service->createShippingRequest(
            $user,
            $address['id'],
            $prizes,
            'shipping-idempotency-0002',
            (string) Str::uuid7()
        );
        $replay = $service->createShippingRequest(
            $user,
            $address['id'],
            array_reverse($prizes),
            'shipping-idempotency-0002',
            (string) Str::uuid7()
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame(1, DB::table('shipping_requests')->count());
        self::assertSame(3, DB::table('shipping_request_items')->count());
        self::assertSame(3, DB::table('user_prizes')
            ->where('status', 'shipping_requested')->count());
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'shipping.requested:'.$first['id'])->count());

        $admin = $this->admin();
        $packing = $service->transitionShipping(
            $admin,
            $first['id'],
            'packing',
            null,
            null,
            'packing_started',
            (string) Str::uuid7()
        );
        self::assertSame('packing', $packing['status']);
        $requestedAgain = $service->transitionShipping(
            $admin,
            $first['id'],
            'requested',
            null,
            null,
            'packing_corrected',
            (string) Str::uuid7()
        );
        self::assertSame('requested', $requestedAgain['status']);
        $service->transitionShipping(
            $admin,
            $first['id'],
            'packing',
            null,
            null,
            'packing_restarted',
            (string) Str::uuid7()
        );
        try {
            $service->transitionShipping(
                $admin,
                $first['id'],
                'shipped',
                null,
                null,
                null,
                (string) Str::uuid7()
            );
            self::fail('Shipping without Carrier and Tracking Number must fail.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('TRACKING_REQUIRED', $exception->errorCode);
        }
        $shipped = $service->transitionShipping(
            $admin,
            $first['id'],
            'shipped',
            'fixture-carrier',
            'fixture-tracking-reference',
            'shipment_confirmed',
            (string) Str::uuid7()
        );
        self::assertSame('fixture-tracking-reference', $shipped['tracking_number']);
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'shipping.completed:'.$first['id'])->count());
        $packingAgain = $service->transitionShipping(
            $admin,
            $first['id'],
            'packing',
            null,
            null,
            'shipment_corrected',
            (string) Str::uuid7()
        );
        self::assertSame('fixture-carrier', $packingAgain['carrier_code']);
        self::assertSame('fixture-tracking-reference', $packingAgain['tracking_number']);
        $shippedAgain = $service->transitionShipping(
            $admin,
            $first['id'],
            'shipped',
            null,
            null,
            'shipment_reconfirmed',
            (string) Str::uuid7()
        );
        self::assertSame('fixture-tracking-reference', $shippedAgain['tracking_number']);
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'shipping.completed:'.$first['id'])->count());
        $delivered = $service->transitionShipping(
            $admin,
            $first['id'],
            'delivered',
            null,
            null,
            'delivery_confirmed',
            (string) Str::uuid7()
        );
        self::assertSame('delivered', $delivered['status']);
        self::assertSame(3, DB::table('user_prizes')->where('status', 'delivered')->count());
        $shippedAfterDelivery = $service->transitionShipping(
            $admin,
            $first['id'],
            'shipped',
            'fixture-carrier-updated',
            'fixture-tracking-updated',
            'delivery_corrected',
            (string) Str::uuid7()
        );
        self::assertSame('shipped', $shippedAfterDelivery['status']);
        self::assertSame('fixture-carrier-updated', $shippedAfterDelivery['carrier_code']);
        self::assertSame('fixture-tracking-updated', $shippedAfterDelivery['tracking_number']);
        self::assertSame(3, DB::table('user_prizes')->where('status', 'shipped')->count());
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'shipping.completed:'.$first['id'])->count());
        self::assertSame(9, DB::table('shipping_request_status_histories')->count());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'shipping.status_changed']);
        self::assertDatabaseHas('outbox_messages', ['event_type' => 'shipping.status.changed']);
        try {
            $shippingId = DB::table('shipping_requests')
                ->where('public_id', $first['id'])
                ->value('id');
            DB::table('shipping_request_status_histories')->where('shipping_request_id', $shippingId)
                ->update(['reason_code' => 'tampered']);
            self::fail('Shipping Request history update must fail.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_payment_adjustment_hold_denies_exchange_and_shipping_with_audit(): void
    {
        [$user, $prizes] = $this->fixture(1);
        $paymentId = DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider_code' => 'fixture',
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'JPY',
            'paid_point_amount' => 100,
            'free_point_amount' => 0,
            'plan_name_snapshot' => 'Fixture',
            'plan_code_snapshot' => 'fixture',
            'succeeded_at' => now(),
            'points_granted_at' => now(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adjustmentId = DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $paymentId,
            'type' => 'chargeback',
            'status' => 'manual_review',
            'amount' => 100,
            'currency' => 'JPY',
            'requested_at' => now(),
            'manual_review_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_adjustment_prize_actions')->insert([
            'payment_adjustment_id' => $adjustmentId,
            'user_prize_id' => DB::table('user_prizes')
                ->where('public_id', $prizes[0])
                ->value('id'),
            'action_type' => 'hold',
            'status' => 'completed',
            'requested_at' => now(),
            'mail_status' => 'not_requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(V2PrizeShippingService::class);

        foreach ([
            fn () => $service->exchange(
                $user,
                $prizes,
                'exchange-hold-boundary-0001',
                (string) Str::uuid7()
            ),
            function () use ($service, $user, $prizes): array {
                $address = $service->createAddress(
                    $user,
                    $this->address(),
                    (string) Str::uuid7()
                );

                return $service->createShippingRequest(
                    $user,
                    $address['id'],
                    $prizes,
                    'shipping-hold-boundary-0001',
                    (string) Str::uuid7()
                );
            },
        ] as $operation) {
            try {
                $operation();
                self::fail('An active Payment Adjustment hold must deny the operation.');
            } catch (V2PrizeShippingException $exception) {
                self::assertSame('PRIZE_ON_PAYMENT_HOLD', $exception->errorCode);
            }
        }

        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'prize.exchange_rejected_hold',
            'outcome' => 'failure',
            'reason_code' => 'prize_on_payment_hold',
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'shipping.request_rejected_hold',
            'outcome' => 'failure',
            'reason_code' => 'prize_on_payment_hold',
        ]);
        self::assertSame(0, DB::table('prize_exchange_requests')->count());
        self::assertSame(0, DB::table('shipping_requests')->count());
    }

    public function test_large_prize_lists_bulk_exchange_and_shipping_record_performance(): void
    {
        [$user, $prizes] = $this->fixture(1000);
        $service = app(V2PrizeShippingService::class);
        $listDurations = [];
        $listQueries = 0;
        $cursor = null;
        $listed = 0;
        do {
            [$page, $duration, $queries] = $this->measure(
                fn (): array => $service->prizes($user, $cursor, 100)
            );
            $listed += count($page['items']);
            $cursor = $page['next_cursor'];
            $listDurations[] = $duration;
            $listQueries += $queries;
        } while ($cursor !== null);
        self::assertSame(1000, $listed);
        self::assertLessThanOrEqual(10, $listQueries);

        $exchangeDurations = [];
        $exchangeQueries = [];
        for ($index = 0; $index < 5; $index++) {
            [$response, $duration, $queries] = $this->measure(
                fn (): array => $service->exchange(
                    $user,
                    array_slice($prizes, $index * 100, 100),
                    sprintf('exchange-performance-%04d', $index),
                    (string) Str::uuid7()
                )
            );
            self::assertSame(100, $response['exchanged_count']);
            $exchangeDurations[] = $duration;
            $exchangeQueries[] = $queries;
        }

        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $shippingDurations = [];
        $shippingQueries = [];
        for ($index = 0; $index < 5; $index++) {
            [$response, $duration, $queries] = $this->measure(
                fn (): array => $service->createShippingRequest(
                    $user,
                    $address['id'],
                    array_slice($prizes, 500 + ($index * 100), 100),
                    sprintf('shipping-performance-%04d', $index),
                    (string) Str::uuid7()
                )
            );
            self::assertSame(100, $response['prize_count']);
            $shippingDurations[] = $duration;
            $shippingQueries[] = $queries;
        }

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $shippingPage = $service->shippingRequests($user, null, 100);
        $shippingListQueries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        self::assertCount(5, $shippingPage['items']);
        self::assertLessThanOrEqual(2, $shippingListQueries);
        self::assertSame(500, DB::table('user_prizes')->where('status', 'converted')->count());
        self::assertSame(
            500,
            DB::table('user_prizes')->where('status', 'shipping_requested')->count()
        );

        $evidence = [
            'user_prize_list_1000' => [
                'pages' => count($listDurations),
                'query_count' => $listQueries,
                'p50_ms' => $this->percentile($listDurations, 0.50),
                'p95_ms' => $this->percentile($listDurations, 0.95),
            ],
            'exchange_100' => [
                'runs' => 5,
                'query_count_min' => min($exchangeQueries),
                'query_count_max' => max($exchangeQueries),
                'p50_ms' => $this->percentile($exchangeDurations, 0.50),
                'p95_ms' => $this->percentile($exchangeDurations, 0.95),
            ],
            'shipping_100' => [
                'runs' => 5,
                'query_count_min' => min($shippingQueries),
                'query_count_max' => max($shippingQueries),
                'p50_ms' => $this->percentile($shippingDurations, 0.50),
                'p95_ms' => $this->percentile($shippingDurations, 0.95),
            ],
            'shipping_list_query_count' => $shippingListQueries,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        fwrite(STDERR, 'MIG052_PERFORMANCE='.json_encode($evidence, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_shipping_policy_http_requires_user_and_sms_and_is_no_store(): void
    {
        $this->getJson('/api/v2/me/shipping-policy')->assertUnauthorized();
        [$user] = $this->fixture(1);
        Auth::guard('v2_user')->setUser($user);
        $this->getJson('/api/v2/me/shipping-policy')->assertOk()
            ->assertJsonPath('minimum_points', '0')->assertJsonPath('effective_required_points', '1')
            ->assertJsonPath('zero_total_allowed', false)->assertHeader('Cache-Control', 'no-store, private');
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v2/me/shipping-policy')->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
    }

    public static function minimumCases(): array
    {
        return [
            'below' => [8000, 1, 8001, false, false],
            'equal' => [8000, 1, 8000, false, true],
            'above' => [8000, 1, 7999, false, true],
            'unrestricted' => [1, 1, 0, false, true],
            'zero single' => [0, 1, 0, false, false],
            'zero multiple' => [0, 5, 0, false, false],
            'shipping only' => [8000, 1, 8000, true, true],
            '100 maximum points' => [9007199254740991, 100, 9007199254740991, false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('minimumCases')]
    public function test_shipping_minimum_is_atomic_and_integer_safe(int $points, int $count, int $minimum, bool $only, bool $allowed): void
    {
        [$user, $ids] = $this->fixture($count, $points, $only, uniformPoints: true);
        DB::table('shipping_minimum_point_settings')->where('id', 1)->update(['minimum_points' => $minimum, 'revision' => 2]);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $tables = ['shipping_requests', 'shipping_request_items', 'shipping_request_status_histories', 'user_prize_status_histories', 'outbox_messages', 'mail_deliveries', 'idempotency_records', 'point_ledger_entries', 'point_operations'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->count();
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        self::assertTrue($service->prizeDetail($user, $ids[0])['allowed_actions']['shipping']['allowed']);
        try {
            $result = $service->createShippingRequest($user, $address['id'], $ids, (string) Str::uuid7(), (string) Str::uuid7());
            self::assertTrue($allowed, 'Below-minimum shipping must fail');
            $row = DB::table('shipping_requests')->where('public_id', $result['id'])->first();
            self::assertSame($points * $count, (int) $row->selected_point_total_snapshot);
            self::assertSame($minimum, (int) $row->applied_minimum_points_snapshot);
            self::assertSame(2, (int) $row->applied_setting_revision);
        } catch (V2PrizeShippingException $error) {
            self::assertFalse($allowed, $error->errorCode);
            self::assertSame('SHIPPING_MINIMUM_POINTS_NOT_MET', $error->errorCode);
            self::assertSame((string) ($points * $count), $error->details['selected_total_points']);
            self::assertSame((string) (max(1, $minimum) - $points * $count), $error->details['shortfall_points']);
            foreach ($tables as $table) self::assertSame($before[$table], DB::table($table)->count(), $table);
            self::assertSame($count, DB::table('user_prizes')->whereIn('public_id', $ids)->where('status', 'stored')->count());
        }
        self::assertEquals($wallet, DB::table('wallets')->where('user_id', $user->id)->first());
        self::assertSame($before['point_ledger_entries'], DB::table('point_ledger_entries')->count());
    }

    public function test_shipping_minimum_replay_precedes_changed_or_missing_setting(): void
    {
        [$user, $ids] = $this->fixture(1);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $key = (string) Str::uuid7();
        $first = $service->createShippingRequest($user, $address['id'], $ids, $key, (string) Str::uuid7());
        DB::table('shipping_minimum_point_settings')->where('id', 1)->update(['minimum_points' => 9007199254740991, 'revision' => 2]);
        $second = $service->createShippingRequest($user, $address['id'], $ids, $key, (string) Str::uuid7());
        self::assertSame([...$first, 'idempotent_replay' => true], $second);
        DB::table('shipping_minimum_point_settings')->delete();
        self::assertSame($second, $service->createShippingRequest($user, $address['id'], $ids, $key, (string) Str::uuid7()));
        try { $service->shippingPolicy($user); self::fail('Missing setting must fail closed'); }
        catch (V2PrizeShippingException $error) { self::assertSame('SHIPPING_POLICY_UNAVAILABLE', $error->errorCode); }
    }

    public function test_shipping_minimum_mixed_snapshot_values_and_invalid_values(): void
    {
        $service = app(\App\Domain\PrizeShipping\Services\V2ShippingMinimumPointService::class);
        DB::table('shipping_minimum_point_settings')->where('id', 1)->update(['minimum_points' => 5]);
        $snapshot = $service->shippingSnapshot([(object) ['exchange_point_snapshot' => 0], (object) ['exchange_point_snapshot' => 5]]);
        self::assertSame(5, $snapshot['selected_point_total_snapshot']);
        foreach ([-1, 1.5, '9007199254740992', '1e3'] as $invalid) {
            try { $service->shippingSnapshot([(object) ['exchange_point_snapshot' => $invalid]]); self::fail('Corrupt snapshot must fail closed'); }
            catch (V2PrizeShippingException $error) { self::assertSame('SHIPPING_POLICY_UNAVAILABLE', $error->errorCode); }
        }
    }

    public function test_mixed_zero_and_shipping_only_prizes_from_different_gachas_ship_together(): void
    {
        [$user, $ids] = $this->fixture(1, 0, mixedGachas: true);
        self::assertSame(2, DB::table('user_prizes')
            ->join('catalog_gacha_version_prizes as p', 'p.id', '=', 'user_prizes.gacha_version_prize_id')
            ->join('catalog_gacha_versions as v', 'v.id', '=', 'p.gacha_version_id')
            ->whereIn('user_prizes.public_id', $ids)->distinct()->count('v.gacha_id'));
        DB::table('shipping_minimum_point_settings')->where('id', 1)->update(['minimum_points' => 8000]);
        $service = app(V2PrizeShippingService::class);
        $address = $service->createAddress($user, $this->address(), (string) Str::uuid7());
        $result = $service->createShippingRequest($user, $address['id'], $ids, (string) Str::uuid7(), (string) Str::uuid7());
        self::assertSame(2, $result['prize_count']);
        $row = DB::table('shipping_requests')->where('public_id', $result['id'])->first();
        self::assertSame(8000, (int) $row->selected_point_total_snapshot);
        foreach (['selected_point_total_snapshot', 'applied_minimum_points_snapshot', 'applied_setting_revision'] as $column) {
            DB::beginTransaction();
            try {
                DB::table('shipping_requests')->where('id', $row->id)->update([$column => null]);
                self::fail('Shipping snapshot must be immutable');
            } catch (QueryException $error) {
                self::assertSame('P0001', $error->getCode());
            } finally {
                DB::rollBack();
            }
        }
    }

    /** @return array{User, list<string>} */
    public function test_closure_forfeits_only_unrequested_prizes_and_preserves_shipping_hold(): void
    {
        [$user, $prizes] = $this->fixture(5);
        $shipping = app(V2PrizeShippingService::class);
        $exchangeKey = 'closure-exchange:'.Str::uuid7();
        $shipping->exchange($user, [$prizes[4]], $exchangeKey, (string) Str::uuid7());
        self::assertTrue($shipping->exchange($user, [$prizes[4]], $exchangeKey, (string) Str::uuid7())['idempotent_replay']);
        $balance = (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance');
        app(V2PointService::class)->consume($user->id, $balance - 12500, 'closure-preview-balance:'.Str::uuid7());
        $address = $shipping->createAddress($user, $this->address(), (string) Str::uuid7());
        $request = $shipping->createShippingRequest($user, $address['id'], [$prizes[0]], 'closure-shipping:'.Str::uuid7(), (string) Str::uuid7());
        DB::table('user_prizes')->whereIn('public_id', [$prizes[0], $prizes[1]])->update(['status' => 'hold']);
        $inventory = DB::table('prize_inventories')->sum('available_quantity');
        $closure = app(\App\Domain\Identity\Services\V2UserClosureService::class);
        self::assertSame(1, $closure->preview($user)['shipping_in_progress_count']);
        self::assertSame(3, $closure->preview($user)['unclaimed_prize_count']);
        self::assertSame(12500, $closure->preview($user)['point_balance']);
        self::assertTrue($closure->preview($user)['can_close']);
        $closure->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[0], 'status' => 'hold']);
        self::assertSame(3, DB::table('user_prizes')->where('user_id', $user->id)->where('status', 'forfeited')->count());
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[4], 'status' => 'converted']);
        self::assertSame(0, DB::table('user_prizes')->where('user_id', $user->id)->where('status', 'exchange_processing')->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 0]);
        self::assertSame($inventory, DB::table('prize_inventories')->sum('available_quantity'));
        self::assertDatabaseHas('shipping_requests', ['public_id' => $request['id'], 'status' => 'requested']);
        $shipping->transitionShipping($this->admin(), $request['id'], 'canceled', null, null, 'closure_cancellation', (string) Str::uuid7());
        self::assertDatabaseHas('user_prizes', ['public_id' => $prizes[0], 'status' => 'forfeited']);
        self::assertSame($inventory, DB::table('prize_inventories')->sum('available_quantity'));
    }

    private function fixture(int $prizeCount, int $exchangePoints = 8000, bool $shippingOnly = false, bool $uniformPoints = false, bool $mixedGachas = false): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $inventoryTotal = max(1000, $prizeCount + 10);
        $fixture['versions'][0]['total_count'] = $inventoryTotal;
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10, 100, 1000];
        $fixture['prizes'][0]['exchange_points'] = $exchangePoints;
        $fixture['prizes'][0]['shipping_only'] = $shippingOnly;
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $index === 0
                ? intdiv($inventoryTotal, 10)
                : $inventoryTotal - intdiv($inventoryTotal, 10);
        }
        unset($relation);
        if ($uniformPoints) {
            foreach ($fixture['prizes'] as &$prize) {
                $prize['exchange_points'] = $exchangePoints;
                $prize['shipping_only'] = $shippingOnly;
            }
            unset($prize);
        }
        if ($mixedGachas) {
            $second = $fixture;
            array_walk_recursive($second, static function (&$value): void {
                if (is_string($value)) $value = str_replace(
                    ['0198a001', 'fixture', 'cards', 'featured', 'Ab3Def7Gh9J'],
                    ['0198a002', 'secondfixture', 'secondcards', 'secondfeatured', 'Bc4Efg8Hi0K'],
                    $value
                );
            });
            foreach ($second['prizes'] as &$prize) {
                $prize['exchange_points'] = 8000;
                $prize['shipping_only'] = true;
            }
            unset($prize);
            foreach ($second as $section => $records) {
                if (is_array($records)) $fixture[$section] = [...$fixture[$section], ...$records];
            }
            $fixture['expected_record_count'] *= 2;
        }
        // Import both gachas once: the importer initializes every rank master.
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $user = $this->user();
        $existingIds = DB::table('user_prizes')->where('user_id', $user->id)->pluck('id')->all();
        app(V2PointService::class)->grantFree(
            $user->id,
            1_000_000,
            'prize-shipping-points-'.Str::uuid()
        );
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int =>
                    intdiv(5_000 * $maximum, 1_000_000) + 1
            )
        );
        $drawCount = in_array($prizeCount, [1, 5, 10, 100, 1000], true)
            ? $prizeCount
            : 5;
        foreach ($fixture['gachas'] as $gacha) {
            app(V2DrawService::class)->create(
                $user,
                $gacha['public_id'],
                $drawCount,
                'prize-shipping-draw-'.Str::uuid(),
                (string) Str::uuid7()
            );
        }
        $prizeCount *= count($fixture['gachas']);
        $ids = DB::table('user_prizes')
            ->where('user_id', $user->id)
            ->whereNotIn('id', $existingIds)
            ->orderBy('id')
            ->limit($prizeCount)
            ->pluck('public_id')
            ->all();
        self::assertCount($prizeCount, $ids);

        return [$user, $ids];
    }

    private function placePaymentHold(User $user, string $prizePublicId): void
    {
        $paymentId = DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider_code' => 'fixture',
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'JPY',
            'paid_point_amount' => 100,
            'free_point_amount' => 0,
            'plan_name_snapshot' => 'Fixture',
            'plan_code_snapshot' => 'fixture',
            'succeeded_at' => now(),
            'points_granted_at' => now(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adjustmentId = DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $paymentId,
            'type' => 'chargeback',
            'status' => 'manual_review',
            'amount' => 100,
            'currency' => 'JPY',
            'requested_at' => now(),
            'manual_review_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_adjustment_prize_actions')->insert([
            'payment_adjustment_id' => $adjustmentId,
            'user_prize_id' => DB::table('user_prizes')
                ->where('public_id', $prizePublicId)
                ->value('id'),
            'action_type' => 'hold',
            'status' => 'completed',
            'requested_at' => now(),
            'mail_status' => 'not_requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{mixed, float, int} */
    private function measure(callable $operation): array
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $startedAt = hrtime(true);
        $result = $operation();
        $duration = (hrtime(true) - $startedAt) / 1_000_000;
        $queries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return [$result, round($duration, 3), $queries];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(count($values) * $percentile) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    private function user(): User
    {
        $user = User::query()->create([
            'email_display' => 'prize-'.Str::uuid().'@example.test',
            'email_normalized' => 'prize-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        UserPhoneNumber::query()->create([
            'user_id' => $user->getKey(),
            'phone_ciphertext' => Crypt::encryptString('+8190'.random_int(10_000_000, 99_999_999)),
            'phone_hmac' => hash('sha256', 'shipping-phone-'.Str::uuid()),
            'verified_at' => now(),
        ]);

        return $user;
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'email_display' => 'shipping-admin@example.test',
            'email_normalized' => 'shipping-admin@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Operator,
            'state' => V2AdminState::Active,
        ]);
    }

    /** @return array<string, string|null> */
    private function address(): array
    {
        return [
            'recipient_name' => '検証用受取人',
            'postal_code' => '000-0000',
            'prefecture' => '検証県',
            'city' => '検証市',
            'street' => '検証町1-2-3',
            'building' => '検証ビル4F',
            'phone_number' => '000-0000-0000',
        ];
    }

    /** @return array<string, mixed> */
    private function historyRow(int $prizeId): array
    {
        return [
            'user_prize_id' => $prizeId,
            'from_status' => 'stored',
            'to_status' => 'hold',
            'actor_type' => 'system',
            'actor_public_id' => null,
            'actor_role' => null,
            'reason_code' => 'test_hold',
            'request_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
