<?php

namespace Tests\Unit;

use App\Domain\Line\Services\V2LineDeliverySegment;
use PHPUnit\Framework\TestCase;

final class V2LineDeliverySegmentTest extends TestCase
{
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
