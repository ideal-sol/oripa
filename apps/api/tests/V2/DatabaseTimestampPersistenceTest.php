<?php

namespace Tests\V2;

use App\Models\V2\ContentBanner;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DatabaseTimestampPersistenceTest extends TestCase
{
    private CarbonImmutable $instant;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('Asia/Tokyo', config('app.timezone'));
        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame(DB::connection()->getConfig('timezone'), DB::selectOne('SHOW TIME ZONE')->TimeZone);
        DB::beginTransaction();
        $this->instant = CarbonImmutable::parse('2026-09-17T10:28:20+09:00');
        Carbon::setTestNow($this->instant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_helper_keeps_inputs_immutable_and_matches_existing_second_precision(): void
    {
        foreach ([Carbon::parse('2026-09-17T10:28:20.987654+09:00'), $this->instant->utc(), new DateTimeImmutable('2026-09-17T10:28:20+09:00')] as $instant) {
            $before = $instant->format('Y-m-d H:i:s.uP');
            self::assertSame('2026-09-17 01:28:20+00:00', V2DatabaseTimestamp::format($instant));
            self::assertSame($before, $instant->format('Y-m-d H:i:s.uP'));
        }
        self::assertNull(V2DatabaseTimestamp::format(null));
        $midnight = Carbon::parse('2026-09-18T00:00:00+09:00');
        self::assertSame('2026-09-17 15:00:00+00:00', V2DatabaseTimestamp::format($midnight));
        self::assertSame('2026-09-18', $midnight->toDateString());
    }

    public function test_query_builder_raw_sql_and_bulk_bindings_preserve_instants(): void
    {
        $identifier = DB::table('content_banners')->insertGetId($this->row());
        $this->assertInstant($identifier, 'created_at', $this->instant);
        DB::table('content_banners')->where('id', $identifier)->update([
            'updated_at' => V2DatabaseTimestamp::format($this->instant->addMinute()->utc()),
        ]);
        $this->assertInstant($identifier, 'updated_at', $this->instant->addMinute());
        DB::update('UPDATE content_banners SET updated_at = ? WHERE id = ?', [
            V2DatabaseTimestamp::format($this->instant->addMinutes(2)), $identifier,
        ]);
        $this->assertInstant($identifier, 'updated_at', $this->instant->addMinutes(2));

        $rows = [$this->row(), $this->row()];
        ContentBanner::query()->insert($rows);
        $identifiers = ContentBanner::query()->whereIn('public_id', array_column($rows, 'public_id'))->pluck('id');
        self::assertCount(2, $identifiers);
        ContentBanner::query()->whereIn('id', $identifiers)->update([
            'updated_at' => V2DatabaseTimestamp::format($this->instant->addMinutes(3)),
        ]);
        foreach ($identifiers as $bulkIdentifier) {
            $this->assertInstant($bulkIdentifier, 'created_at', $this->instant);
            $this->assertInstant($bulkIdentifier, 'updated_at', $this->instant->addMinutes(3));
        }
        DB::table('content_banners')->where('id', $identifier)->update(['updated_at' => $this->instant->utc()->toIso8601String()]);
        $this->assertInstant($identifier, 'updated_at', $this->instant);
    }

    public function test_insert_or_ignore_and_upsert_keep_explicit_instants_and_original_creation(): void
    {
        $row = $this->row();
        self::assertSame(1, DB::table('content_banners')->insertOrIgnore($row));
        self::assertSame(0, DB::table('content_banners')->insertOrIgnore($row));
        $identifier = (int) DB::table('content_banners')->where('public_id', $row['public_id'])->value('id');
        DB::table('content_banners')->upsert([
            [...$row, 'updated_at' => V2DatabaseTimestamp::format($this->instant->addMinute()->utc())],
        ], ['public_id'], ['updated_at']);
        $this->assertInstant($identifier, 'created_at', $this->instant);
        $this->assertInstant($identifier, 'updated_at', $this->instant->addMinute());
    }

    public function test_model_create_save_touch_and_bulk_automatic_timestamps_preserve_instants(): void
    {
        Carbon::setTestNow($this->instant->setMicrosecond(987654));
        $banner = ContentBanner::unguarded(fn () => ContentBanner::query()->create([
            'code' => 'timestamp-'.Str::uuid7(), 'status' => 'draft',
        ]));
        $this->assertInstant($banner->id, 'created_at', $this->instant);
        $this->assertInstant($banner->id, 'updated_at', $this->instant);
        self::assertTrue($banner->fresh()->created_at->equalTo($this->instant));
        self::assertSame('2026-09-17T01:28:20.000000Z', $banner->fresh()->toArray()['created_at']);

        Carbon::setTestNow($this->instant->addMinute());
        $banner->code = 'updated-'.Str::uuid7();
        $banner->save();
        $this->assertInstant($banner->id, 'updated_at', $this->instant->addMinute());
        Carbon::setTestNow($this->instant->addMinutes(2));
        $banner->touch();
        $this->assertInstant($banner->id, 'updated_at', $this->instant->addMinutes(2));
        Carbon::setTestNow($this->instant->addMinutes(3));
        ContentBanner::query()->whereKey($banner->id)->update(['status' => 'draft']);
        $this->assertInstant($banner->id, 'updated_at', $this->instant->addMinutes(3));
        $this->assertInstant($banner->id, 'created_at', $this->instant);
    }

    public function test_explicit_bulk_touch_equivalent_does_not_repair_historical_timestamps(): void
    {
        foreach ([$this->instant, $this->instant->addHours(9)] as $historical) {
            $row = $this->row();
            $row['created_at'] = V2DatabaseTimestamp::format($historical);
            $identifier = DB::table('content_banners')->insertGetId($row);
            ContentBanner::query()->whereKey($identifier)->update([
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $this->assertInstant($identifier, 'updated_at', $this->instant);
            $this->assertInstant($identifier, 'created_at', $historical);
            $banner = ContentBanner::query()->findOrFail($identifier);
            $banner->code = 'historical-'.Str::uuid7();
            $banner->save();
            $this->assertInstant($identifier, 'created_at', $historical);
        }
    }

    private function row(): array
    {
        return [
            'public_id' => (string) Str::uuid7(), 'code' => 'timestamp-'.Str::uuid7(), 'status' => 'draft',
            'created_at' => V2DatabaseTimestamp::format($this->instant),
            'updated_at' => V2DatabaseTimestamp::format($this->instant),
        ];
    }

    private function assertInstant(int $identifier, string $column, DateTimeInterface $expected): void
    {
        $actual = DB::table('content_banners')->where('id', $identifier)->value($column);
        self::assertSame($expected->getTimestamp(), CarbonImmutable::parse($actual)->getTimestamp());
    }
}
