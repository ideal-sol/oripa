<?php

namespace Tests\Unit;

use App\Domain\Line\ValueObjects\V2LineDeliveryPacing;
use PHPUnit\Framework\TestCase;

final class V2LineDeliveryPacingTest extends TestCase
{
    public function test_retry_window_exact_boundary_and_clock_regression(): void
    {
        $sender = new \App\Domain\Line\Services\V2LineDeliverySender;
        $first = '2026-11-16T00:00:00Z';
        self::assertFalse($sender->retryExpired($first, \Carbon\CarbonImmutable::parse('2026-11-16T22:59:59Z')));
        self::assertFalse($sender->retryExpired($first, \Carbon\CarbonImmutable::parse('2026-11-16T23:00:00Z')));
        self::assertTrue($sender->retryExpired($first, \Carbon\CarbonImmutable::parse('2026-11-16T23:00:00.000001Z')));
        self::assertTrue($sender->retryExpired($first, \Carbon\CarbonImmutable::parse('2026-11-15T23:59:59Z')));
    }

    public function test_push_boundary_and_multicast_chunking(): void
    {
        $pacing = new V2LineDeliveryPacing;
        self::assertSame(0, $pacing->offsetSeconds('push', 9));
        self::assertSame(5, $pacing->offsetSeconds('push', 10));
        self::assertSame(10, $pacing->offsetSeconds('push', 20));
        self::assertSame(0, $pacing->offsetSeconds('multicast', 10));
        foreach ([0 => 0, 1 => 1, 499 => 1, 500 => 1, 501 => 2, 1000 => 2, 1001 => 3] as $people => $batches) {
            self::assertSame($batches, $pacing->batchCount($people, false));
            self::assertSame($people, $pacing->batchCount($people, true));
        }
    }

    public function test_restart_spreads_overdue_pending_batches_from_resume_time(): void
    {
        $resume = new \DateTimeImmutable('2026-11-16T12:00:00Z');
        $pacing = new V2LineDeliveryPacing;
        $schedule = $pacing->resumeSchedule('push', range(101, 121), $resume);
        self::assertEquals($resume, $schedule[110]);
        self::assertEquals($resume->modify('+5 seconds'), $schedule[111]);
        self::assertEquals($resume->modify('+10 seconds'), $schedule[121]);
        $multicast = (new V2LineDeliveryPacing(10, 5, 7))->resumeSchedule('multicast', [8, 11, 20], $resume);
        self::assertEquals($resume->modify('+14 seconds'), $multicast[20]);
        self::assertArrayNotHasKey(9, $multicast); // A retrying batch omitted by the caller is untouched.
    }

    public function test_invalid_configuration_and_positions_fail_closed(): void
    {
        foreach ([[0, 5, 0], [1001, 5, 0], [10, -1, 0], [10, 3601, 0], [10, 5, -1], [10, 5, 3601]] as $values) {
            try {
                new V2LineDeliveryPacing(...$values);
                self::fail('Invalid pacing accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new V2LineDeliveryPacing)->offsetSeconds('push', -1);
    }

    public function test_duplicate_pending_ids_cannot_overwrite_schedule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new V2LineDeliveryPacing)->resumeSchedule('push', [1, 1], new \DateTimeImmutable);
    }

    public function test_duration_overflow_is_rejected(): void
    {
        $this->expectException(\OverflowException::class);
        (new V2LineDeliveryPacing(1, 3600))->offsetSeconds('push', PHP_INT_MAX);
    }
}
