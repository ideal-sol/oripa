<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\DTO\SmsMessage;
use App\Domain\Notification\DTO\SmsSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogSmsSender implements SmsSender
{
    public function send(SmsMessage $message): SmsSendResult
    {
        $messageId = 'log_'.Str::uuid()->toString();

        Log::info('SMS notification logged.', [
            'message_id' => $messageId,
            'to' => $message->to,
            'body' => $message->body,
            'user_id' => $message->userId,
            'purpose' => $message->purpose,
            'metadata' => $message->metadata,
        ]);

        return SmsSendResult::sent('log', $messageId);
    }
}
