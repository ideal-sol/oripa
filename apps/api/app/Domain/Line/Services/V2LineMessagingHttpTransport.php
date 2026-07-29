<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Contracts\V2LineMessagingTransport;
use App\Domain\Line\ValueObjects\V2LineReplyResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class V2LineMessagingHttpTransport implements V2LineMessagingTransport
{
    public function replyText(
        #[SensitiveParameter] string $replyToken,
        string $message
    ): V2LineReplyResult {
        $accessToken = config('v2_line.messaging.channel_access_token');
        $endpoint = config('v2_line.messaging.reply_endpoint');
        $timeout = config('v2_line.messaging.http_timeout_seconds');
        if (
            ! is_string($accessToken)
            || $accessToken === ''
            || ! is_string($endpoint)
            || $endpoint !== 'https://api.line.me/v2/bot/message/reply'
            || ! is_int($timeout)
            || $timeout < 1
            || $timeout > 10
        ) {
            return new V2LineReplyResult(false, 'configuration_unavailable');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($accessToken)
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->post($endpoint, [
                    'replyToken' => $replyToken,
                    'messages' => [[
                        'type' => 'text',
                        'text' => $message,
                    ]],
                ]);
        } catch (ConnectionException) {
            return new V2LineReplyResult(false, 'timeout');
        } catch (Throwable) {
            return new V2LineReplyResult(false, 'transport_failure');
        }

        if ($response->successful()) {
            return new V2LineReplyResult(true);
        }
        if ($response->status() === 429) {
            return new V2LineReplyResult(false, 'rate_limited');
        }
        if ($response->serverError()) {
            return new V2LineReplyResult(false, 'provider_unavailable');
        }

        return new V2LineReplyResult(false, 'provider_rejected');
    }
}
