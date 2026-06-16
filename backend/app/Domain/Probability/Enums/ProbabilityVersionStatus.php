<?php

namespace App\Domain\Probability\Enums;

enum ProbabilityVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
