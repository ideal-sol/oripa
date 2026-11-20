<?php

namespace Tests\Unit;

use App\Domain\Line\Services\V2LineProfileName as Name;
use PHPUnit\Framework\TestCase;

final class V2LineProfileNameTest extends TestCase
{
    public function test_name_normalization_and_member_fallback(): void
    {
        self::assertSame('LINE 名', Name::normalize(" LINE\n名 "));
        foreach ([null, '', "\xff", str_repeat('あ', 256), [], "\0\n"] as $invalid) self::assertNull(Name::normalize($invalid));
        self::assertSame('LINE名', Name::forUser((object)['display_name'=>'LINE名','full_name'=>'氏名']));
        self::assertSame('氏名', Name::forUser((object)['display_name'=>null,'full_name'=>'氏名']));
        self::assertSame('お客様', Name::forUser(null));
    }
}
