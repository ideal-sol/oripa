<?php

namespace App\Domain\Content\Enums;

enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
}
