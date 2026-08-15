<?php

namespace App\Http\Controllers\V2;

use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2DrawController
{
    public function __construct(
        private readonly V2DrawService $draws,
        private readonly V2RateLimiter $rateLimiter
    ) {
    }

    public function store(Request $request, string $gachaId): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            $user = $this->user();
            $count = filter_var($request->input('draw_count'), FILTER_VALIDATE_INT);
            $idempotencyKey = $request->header('Idempotency-Key');
            if ($count === false || ! is_string($idempotencyKey)) {
                throw new V2DrawException(
                    'INVALID_DRAW_REQUEST',
                    422,
                    'The Draw request is invalid.'
                );
            }
            $this->rateLimiter->assertSubject('draw_mutation', $user->public_id);
            $result = $this->draws->create(
                $user,
                $gachaId,
                $count,
                $idempotencyKey,
                $requestId
            );

            return $this->success($result, $requestId);
        } catch (V2DrawException $exception) {
            return $this->problem($exception, $requestId);
        }
    }

    public function show(Request $request, string $drawRequestId): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return $this->success(
                $this->draws->get($this->user(), $drawRequestId),
                $requestId
            );
        } catch (V2DrawException $exception) {
            return $this->problem($exception, $requestId);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            $cursor = $request->query('cursor');
            if ($cursor !== null && ! is_string($cursor)) {
                throw new V2DrawException('INVALID_CURSOR', 422, 'The cursor is invalid.');
            }
            $limit = filter_var($request->query('limit', 20), FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new V2DrawException(
                    'INVALID_PAGINATION',
                    422,
                    'The pagination input is invalid.'
                );
            }

            return $this->success(
                $this->draws->history($this->user(), $cursor, $limit),
                $requestId,
                true
            );
        } catch (V2DrawException $exception) {
            return $this->problem($exception, $requestId, true);
        }
    }

    private function user(): User
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2DrawException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function success(
        array $body,
        string $requestId,
        bool $private = false
    ): JsonResponse
    {
        $headers = [
            'Cache-Control' => $private ? 'private, no-store' : 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ];
        if ($private) {
            $headers['Vary'] = 'Cookie';
        }

        return response()->json($body, 200, $headers);
    }

    private function problem(
        V2DrawException $exception,
        string $requestId,
        bool $private = false
    ): JsonResponse
    {
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

        $headers = [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => $private ? 'private, no-store' : 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ];
        if ($private) {
            $headers['Vary'] = 'Cookie';
        }
        $response = response()->json($body, $exception->status, $headers);
        if ($exception->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
        }

        return $response;
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }
}
