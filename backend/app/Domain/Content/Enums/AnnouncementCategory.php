<?php

namespace App\Domain\Content\Enums;

enum AnnouncementCategory: string
{
    case Notice = 'notice';
    case LandingPage = 'lp';
}
