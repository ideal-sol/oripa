<?php

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTO\SmsMessage;
use App\Domain\Notification\DTO\SmsSendResult;

interface SmsSender
{
    public function send(SmsMessage $message): SmsSendResult;
}
