<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Exceptions\V2AdminPointAdjustmentException;
use App\Domain\Point\Services\V2AdminPointAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminUserPointAdjustmentController
{
    public function __construct(
        private readonly V2AdminPointAdjustmentService $adjustments,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            $this->assertExactFields($request);
            $currentPassword = $request->input('current_password');
            if (! is_string($currentPassword) || $currentPassword === '') {
                throw $this->invalid();
            }
            $result = $this->adjustments->execute(
                $this->authorization->context($request, $requestId),
                $userId,
                $this->idempotencyKey($request),
                $request->only(['point_type', 'direction', 'amount', 'reason']),
                $currentPassword
            );

            return response()->json([
                ...$result,
                'request_id' => $requestId,
            ], 200, [
                ...$this->headers($requestId),
                'Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false',
            ]);
        } catch (V2AdminPointAdjustmentException|V2AuthenticationException $exception) {
            return $this->problem($requestId, $exception);
        }
    }

    private function assertExactFields(Request $request): void
    {
        if (array_diff(array_keys($request->all()), [
            'point_type',
            'direction',
            'amount',
            'reason',
            'current_password',
        ]) !== []) {
            throw $this->invalid();
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            throw $this->invalid();
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

    private function problem(
        string $requestId,
        V2AdminPointAdjustmentException|V2AuthenticationException $exception
    ): JsonResponse {
        $retryAfter = $exception instanceof V2AuthenticationException
            ? $exception->retryAfterSeconds
            : $exception->retryAfter;
        $headers = [
            ...$this->headers($requestId),
            'Content-Type' => 'application/problem+json',
        ];
        if (is_int($retryAfter)) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
            'title' => $exception->getMessage(),
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'request_id' => $requestId,
            'retryable' => $exception->retryable,
        ], $exception->status, $headers);
    }

    /** @return array<string, string> */
    private function headers(string $requestId): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ];
    }

    private function invalid(): V2AdminPointAdjustmentException
    {
        return new V2AdminPointAdjustmentException(
            'POINT_ADJUSTMENT_INVALID',
            422,
            'The point adjustment request is invalid.'
        );
    }
}
