<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Exceptions\V2AdminUserReadException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2AdminUserReadService;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminUserController
{
    public function __construct(
        private readonly V2AdminUserReadService $users,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->users->users(
            $this->context($request),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->users->user(
            $this->context($request),
            $userId
        ));
    }

    public function gachaHistory(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->users->gachaHistory(
            $this->context($request),
            $userId,
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    private function context(Request $request): V2AdminAuthorizationContext
    {
        return $this->authorization->context($request, $this->requestId($request));
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), 200, $this->headers($requestId));
        } catch (
            V2AdminUserReadException
            |V2AuthenticationException
            |V2ReportingException $exception
        ) {
            return $this->problem($requestId, $exception);
        }
    }

    private function problem(
        string $requestId,
        V2AdminUserReadException|V2AuthenticationException|V2ReportingException $exception
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

    private function requestId(Request $request): string
    {
        $existing = $request->attributes->get('v2_admin_user_request_id');
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }
        $header = $request->header('X-Request-Id');
        $requestId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid7();
        $request->attributes->set('v2_admin_user_request_id', $requestId);

        return $requestId;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function limit(Request $request): int
    {
        $value = $request->query('limit', 20);

        return is_numeric($value) ? (int) $value : 0;
    }
}
