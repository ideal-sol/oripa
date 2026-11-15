<?php

namespace App\Domain\Line\Enums;

enum V2LineDeliveryPurpose: string
{
    case Transactional = 'transactional';
    case Promotional = 'promotional';
}
