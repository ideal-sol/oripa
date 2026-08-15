<?php

namespace App\Http\Controllers\V2;

use App\Domain\Point\Exceptions\V2PointReadException;
use App\Domain\Point\Services\V2CurrentUserPointReadService;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2CurrentUserPointController
{
    public function __construct(private readonly V2CurrentUserPointReadService $service)
    {
    }

    public function wallet(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->wallet($this->user()));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request): array {
            $cursor = $request->query('cursor');
            if ($cursor !== null && ! is_string($cursor)) {
                throw new V2PointReadException('INVALID_CURSOR', 422, 'The cursor is invalid.');
            }

            return $this->service->history(
                $this->user(),
                $cursor,
                (int) $request->query('limit', 20)
            );
        });
    }

    private function user(): User
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2PointReadException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        return $user;
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), 200, $this->headers($requestId));
        } catch (V2PointReadException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $requestId,
                'retryable' => $exception->retryable,
            ], $exception->status, [
                ...$this->headers($requestId),
                'Content-Type' => 'application/problem+json',
            ]);
        }
    }

    /** @return array<string, string> */
    private function headers(string $requestId): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ];
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }
}
