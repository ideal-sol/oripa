<?php

namespace App\Domain\Sms\Contracts;

use App\Domain\Sms\Values\V2SmsDeliveryResult;
use SensitiveParameter;

interface V2SmsProvider
{
    public function deliver(
        #[SensitiveParameter] string $canonicalPhone,
        #[SensitiveParameter] string $verificationCode
    ): V2SmsDeliveryResult;
}
