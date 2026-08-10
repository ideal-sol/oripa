<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        self::assertSame(
            '2026-09-28 09:00:00+09:00',
            CarbonImmutable::parse($row->storage_expires_at)->format('Y-m-d H:i:sP')
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
        self::assertSame('S', $page['items'][0]['presentation']['rank']['code']);
        self::assertTrue($page['items'][0]['allowed_actions']['shipping']['allowed']);
        self::assertTrue($page['items'][0]['allowed_actions']['point_exchange']['allowed']);
        self::assertTrue($page['items'][0]['allowed_actions']['selection']['allowed']);
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal_id', $serialized);
        self::assertStringNotContainsString('exchange_point_snapshot', $serialized);
        self::assertStringNotContainsString('individual_ppm', $serialized);
        self::assertStringNotContainsString('cost_price', $serialized);
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

    public function test_zero_exchange_points_allow_shipping_but_not_point_exchange(): void
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

    public function test_storage_expiry_boundary_disallows_all_actions(): void
    {
        [$user, $prizes] = $this->fixture(1);
        $expiresAt = DB::table('user_prizes')
            ->where('public_id', $prizes[0])
            ->value('storage_expires_at');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($expiresAt));

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
        try {
            $service->transitionShipping(
                $admin,
                $first['id'],
                'packing',
                null,
                null,
                null,
                (string) Str::uuid7()
            );
            self::fail('Terminal Shipping transition must not rewind.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame('SHIPPING_TRANSITION_NOT_ALLOWED', $exception->errorCode);
        }
        self::assertDatabaseHas('audit_logs', ['action_code' => 'shipping.status_changed']);
        self::assertDatabaseHas('outbox_messages', ['event_type' => 'shipping.status.changed']);
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

    /** @return array{User, list<string>} */
    private function fixture(int $prizeCount, int $exchangePoints = 8000): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = max(1000, $prizeCount + 10);
        $fixture['prizes'][0]['exchange_points'] = $exchangePoints;
        foreach ($fixture['gacha_prizes'] as &$relation) {
            $relation['initial_inventory'] = max(1000, $prizeCount + 10);
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $user = $this->user();
        app(V2PointService::class)->grantFree(
            $user->id,
            1_000_000,
            now()->addYear(),
            'prize-shipping-points-'.Str::uuid()
        );
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(static fn (): int => 5_000)
        );
        $drawCount = in_array($prizeCount, [1, 5, 10, 100, 1000], true)
            ? $prizeCount
            : 5;
        app(V2DrawService::class)->create(
            $user,
            $fixture['gachas'][0]['public_id'],
            $drawCount,
            'prize-shipping-draw-'.Str::uuid(),
            (string) Str::uuid7()
        );
        $ids = DB::table('user_prizes')
            ->where('user_id', $user->id)
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
        return User::query()->create([
            'email_display' => 'prize-'.Str::uuid().'@example.test',
            'email_normalized' => 'prize-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
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
