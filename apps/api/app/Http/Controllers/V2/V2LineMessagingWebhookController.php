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

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $rawBody = $request->getContent();
        if (! $this->lineFriends->verifySignature(
            $rawBody,
            $request->header('X-Line-Signature')
        )) {
            return $this->problem(
                $requestId,
                new V2LineMessagingException(
                    'LINE_WEBHOOK_SIGNATURE_INVALID',
                    401,
                    'The LINE webhook could not be authenticated.'
                )
            );
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
            $this->lineFriends->handleEvents($payload['events'], $requestId);
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
