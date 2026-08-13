<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AdminUserStateException;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2AdminUserStateService;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminUserStateController
{
    public function __construct(
        private readonly V2AdminUserStateService $states,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            $result = $this->states->update(
                $this->authorization->context($request, $requestId),
                $userId,
                $request->json()->all(),
                $this->idempotencyKey($request)
            );

            return response()->json([
                ...$result,
                'request_id' => $requestId,
            ], 200, [
                'Cache-Control' => 'private, no-store',
                'Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false',
                'X-Oripa-Api-Version' => '2',
                'X-Request-Id' => $requestId,
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2AdminUserStateException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $requestId,
                'retryable' => $exception->retryable,
            ], $exception->status, [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/problem+json',
                'X-Oripa-Api-Version' => '2',
                'X-Request-Id' => $requestId,
            ]);
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            throw new V2AdminUserStateException(
                'ADMIN_USER_STATE_INVALID',
                422,
                'The User state request is invalid.'
            );
        }

        return $key;
    }

    private function requestId(Request $request): string
    {
        $header = $request->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid7();
    }
}
