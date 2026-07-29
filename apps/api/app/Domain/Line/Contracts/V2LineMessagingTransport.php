<?php

namespace App\Domain\Line\Contracts;

use App\Domain\Line\ValueObjects\V2LineReplyResult;
use SensitiveParameter;

interface V2LineMessagingTransport
{
    public function replyText(
        #[SensitiveParameter] string $replyToken,
        string $message
    ): V2LineReplyResult;
}
