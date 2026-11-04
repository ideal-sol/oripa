<?php

namespace Tests\Unit;

use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExchangeOnlyPrizeActionsTest extends TestCase
{
    private function service(): V2PrizeShippingService
    {
        return (new ReflectionClass(V2PrizeShippingService::class))->newInstanceWithoutConstructor();
    }

    public function test_exchange_only_remains_selectable_for_exchange_but_never_shipping(): void
    {
        $prize = (object) ['status' => 'stored', 'storage_expires_at' => '2026-11-12T00:00:00Z',
            'exchange_point_snapshot' => 100, 'shipping_only_snapshot' => false, 'exchange_only_snapshot' => true];
        $actions = $this->service()->prizeAllowedActions($prize, CarbonImmutable::parse('2026-11-04T00:00:00Z'), false);
        self::assertSame(['allowed' => false, 'unavailable_reason' => 'exchange_only'], $actions['shipping']);
        self::assertTrue($actions['point_exchange']['allowed']);
        self::assertTrue($actions['selection']['allowed']);
        $held = $this->service()->prizeAllowedActions($prize, CarbonImmutable::parse('2026-11-04T00:00:00Z'), true);
        self::assertFalse($held['selection']['allowed']);
        self::assertSame('payment_hold', $held['point_exchange']['unavailable_reason']);
        $expired = $this->service()->prizeAllowedActions($prize, CarbonImmutable::parse('2026-11-12T00:00:00Z'), false);
        self::assertFalse($expired['selection']['allowed']);
        self::assertSame('storage_expired', $expired['point_exchange']['unavailable_reason']);
    }

    public function test_normal_and_shipping_only_rights_are_preserved(): void
    {
        foreach ([false, true] as $shippingOnly) {
            $prize = (object) ['status' => 'stored', 'storage_expires_at' => '2026-11-12T00:00:00Z',
                'exchange_point_snapshot' => 100, 'shipping_only_snapshot' => $shippingOnly, 'exchange_only_snapshot' => false];
            $actions = $this->service()->prizeAllowedActions($prize, CarbonImmutable::parse('2026-11-04T00:00:00Z'), false);
            self::assertTrue($actions['shipping']['allowed']);
            self::assertSame(! $shippingOnly, $actions['point_exchange']['allowed']);
            self::assertTrue($actions['selection']['allowed']);
        }
    }

    public function test_cursors_cannot_cross_status_groups_and_legacy_cursor_survives(): void
    {
        $service = $this->service();
        $class = new ReflectionClass($service);
        $encode = $class->getMethod('encodeCursor');
        $decode = $class->getMethod('decodeCursor');
        self::assertSame('MTIz', $encode->invoke($service, 123));
        self::assertSame(123, $decode->invoke($service, 'MTIz'));
        $stored = $encode->invoke($service, 123, 'stored');
        self::assertSame(123, $decode->invoke($service, $stored, 'stored'));
        $this->expectException(V2PrizeShippingException::class);
        $decode->invoke($service, $stored, 'shipping');
    }
}
