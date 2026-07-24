<?php

namespace App\Http\Responses;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2ProblemDetails
{
    public static function fromAuthentication(
        Request $request,
        V2AuthenticationException $exception
    ): JsonResponse {
        $requestId = $request->headers->get('X-Request-Id');
        if (! is_string($requestId) || $requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $body = [
            'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
            'title' => $exception->getMessage(),
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'request_id' => $requestId,
            'retryable' => $exception->retryable,
        ];
        if ($exception->retryAfterSeconds !== null) {
            $body['retry_after_seconds'] = $exception->retryAfterSeconds;
        }

        $response = response()->json(
            $body,
            $exception->status,
            [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]
        );
        if ($exception->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
        }

        return $response;
    }
}
