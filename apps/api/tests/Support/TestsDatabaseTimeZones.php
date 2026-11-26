<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

trait TestsDatabaseTimeZones
{
    public static function databaseTimeZones(): array
    {
        return [['zone' => 'UTC'], ['zone' => 'Asia/Tokyo']];
    }

    protected function setUpTestsDatabaseTimeZones(): void
    {
        $zone = $this->providedData()['zone'] ?? null;
        if ($zone === null) {
            return;
        }

        $previous = config('database.connections.pgsql.timezone');
        config(['database.connections.pgsql.timezone' => $zone]);
        DB::purge('pgsql');
        $this->beforeApplicationDestroyed(static function () use ($previous): void {
            DB::purge('pgsql');
            config(['database.connections.pgsql.timezone' => $previous]);
        });
    }

    private function assertStoredInstant(DateTimeInterface $expected, string $stored): void
    {
        self::assertSame($expected->format('U.u'), CarbonImmutable::parse($stored)->format('U.u'));
    }

    private function assertDatabaseTimeZone(string $zone): void
    {
        self::assertSame('Asia/Tokyo', config('app.timezone'));
        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame($zone, DB::connection()->getConfig('timezone'));
        self::assertSame($zone, DB::selectOne('SHOW TIME ZONE')->TimeZone);
    }
}
