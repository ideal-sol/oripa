<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineMessagingService
{
    public function verifySignature(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.line.channel_secret');

        if ($secret === '' || ! $signature) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($expected, $signature);
    }

    public function replyText(?string $replyToken, string $message): void
    {
        $accessToken = (string) config('services.line.channel_access_token');

        if (! $replyToken || $accessToken === '' || $message === '') {
            return;
        }

        $response = Http::withToken($accessToken)->post('https://api.line.me/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::warning('LINE reply failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
