<?php

namespace App\Http\Controllers\V2;

use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Line\Services\V2LineFriendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;

final class V2LineMessagingWebhookController
{
    public function __construct(
        private readonly V2LineFriendService $lineFriends
    ) {
    }

    public function __invoke(Request $request, ?string $bot_public_id = null): JsonResponse
    {
        $requestId = $this->requestId($request);
        $rawBody = $request->getContent();
        $bot = $bot_public_id === null
            ? \Illuminate\Support\Facades\DB::table('line_bots')->where('legacy', true)->first()
            : (Str::isUuid($bot_public_id) ? \Illuminate\Support\Facades\DB::table('line_bots')->where('public_id', $bot_public_id)->first() : null);
        $secret = null;
        try {
            if ($bot?->channel_secret_ciphertext !== null) $secret = \Illuminate\Support\Facades\Crypt::decryptString($bot->channel_secret_ciphertext);
            elseif ($bot_public_id === null && $bot?->legacy) $secret = config('v2_line.messaging.channel_secret');
        } catch (\Throwable) {}
        $signature = $request->header('X-Line-Signature');
        if (! is_string($secret) || $secret === '' || ! is_string($signature)
            || ! hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature)) {
            return $this->problem(
                $requestId,
                new V2LineMessagingException(
                    'LINE_WEBHOOK_SIGNATURE_INVALID',
                    401,
                    'The LINE webhook could not be authenticated.'
                )
            );
        }

        $currentBot = app(\App\Domain\Line\Services\V2LineConfiguration::class)->currentBot();
        if (($bot_public_id !== null && ! $bot->is_active) || (int) $currentBot?->id !== (int) $bot->id) {
            return response()->json(['accepted' => true, 'request_id' => $requestId], 200, ['Cache-Control' => 'no-store']);
        }
        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
            if (
                ! is_array($payload)
                || array_diff(array_keys($payload), ['destination', 'events']) !== []
                || ! isset($payload['events'])
                || ! is_array($payload['events'])
                || ! array_is_list($payload['events'])
            ) {
                throw new V2LineMessagingException(
                    'LINE_WEBHOOK_INVALID',
                    422,
                    'The LINE webhook request is invalid.'
                );
            }
            $this->lineFriends->handleEvents($payload['events'], $requestId, (int) $bot->id);
        } catch (JsonException) {
            return $this->problem(
                $requestId,
                new V2LineMessagingException(
                    'LINE_WEBHOOK_INVALID',
                    422,
                    'The LINE webhook request is invalid.'
                )
            );
        } catch (V2LineMessagingException $exception) {
            return $this->problem($requestId, $exception);
        }

        return response()->json([
            'accepted' => true,
            'request_id' => $requestId,
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }

    private function problem(
        string $requestId,
        V2LineMessagingException $exception
    ): JsonResponse {
        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
            'title' => $exception->getMessage(),
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'request_id' => $requestId,
            'retryable' => $exception->retryable,
        ], $exception->status, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }

    private function requestId(Request $request): string
    {
        $header = $request->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid7();
    }
}
