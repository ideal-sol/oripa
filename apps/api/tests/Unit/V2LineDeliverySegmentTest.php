<?php

namespace Tests\Unit;

use App\Domain\Line\Services\V2LineDeliverySegment;
use PHPUnit\Framework\TestCase;

final class V2LineDeliverySegmentTest extends TestCase
{
    public function test_quota_next_month_uses_jst_calendar_at_utc_month_end(): void
    {
        $q=new \App\Domain\Line\Services\V2LineDeliveryQuota;
        foreach (['2026-02-28T15:00:00Z'=>['2026-03-01','2026-04-01'],
            '2026-01-31T14:59:59Z'=>['2026-01-01','2026-02-01'],
            '2026-12-31T15:00:00Z'=>['2027-01-01','2027-02-01']] as $instant=>[$current,$next]) {
            $at=\Carbon\CarbonImmutable::parse($instant);
            self::assertSame($current,$q->month($at)); self::assertSame($next,$q->nextMonth($at));
        }
    }
    public function test_normalizes_rank_set_and_allows_inclusive_equal_bounds(): void
    {
        $s=new V2LineDeliverySegment;
        self::assertSame(['rank_levels'=>[1,7],'purchase_min'=>0,'purchase_max'=>0,'followed_from'=>'2024-02-29'],$s->normalize(['purchase_max'=>0,'rank_levels'=>[7,1,7],'purchase_min'=>0,'followed_from'=>'2024-02-29']));
        self::assertSame([],$s->normalize([]));
    }
    public function test_unknown_fields_sql_strings_dates_and_inverted_bounds_fail_closed(): void
    {
        foreach([['where'=>'1=1'],['purchase_min'=>'0'],['purchase_min'=>-1],['purchase_min'=>2,'purchase_max'=>1],['rank_levels'=>[0]],['rank_levels'=>[]],['registered_from'=>'2026-02-30'],['registered_from'=>'2026-10-10','registered_to'=>'2026-10-09'],['purchase_within_days'=>1,'purchase_absent_days'=>2]] as $input) {
            try { (new V2LineDeliverySegment)->normalize($input); self::fail('Invalid segment accepted'); } catch(\InvalidArgumentException $e) { self::assertSame('LINE_SEGMENT_INVALID',$e->getMessage()); }
        }
    }
}
