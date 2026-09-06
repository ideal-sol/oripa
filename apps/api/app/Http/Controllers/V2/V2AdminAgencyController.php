<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AgencyException;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2AgencyService;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminAgencyController
{
    public function __construct(
        private readonly V2AgencyService $service,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, 'list');
    }

    public function show(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'detail', $agencyId);
    }

    public function issue(Request $request): JsonResponse
    {
        return $this->handle($request, 'issuance');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handle($request, 'create');
    }

    public function update(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'update', $agencyId);
    }

    public function suspend(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'suspend', $agencyId);
    }

    public function reactivate(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'reactivate', $agencyId);
    }

    public function resetPassword(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'password-reset', $agencyId);
    }

    public function reissue(Request $request, string $agencyId): JsonResponse
    {
        return $this->handle($request, 'login-information-reissue', $agencyId);
    }

    private function handle(Request $request, string $operation, ?string $publicId = null): JsonResponse
    {
        $requestId = $request->attributes->get('v2_request_id');
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
            $request->attributes->set('v2_request_id', $requestId);
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
        try {
            $context = $this->authorization->context($request, $requestId);
            $result = match ($operation) {
                'list' => $this->service->listing($context, $request->query('cursor'), (int) $request->query('limit', 50)),
                'detail' => $this->service->detail($context, $publicId),
                'issuance' => $this->service->issue($context),
                default => $this->service->mutate($context, $operation, $publicId, $request->json()->all(), (string) $request->header('Idempotency-Key', '')),
            };

            return response()->json([...$result, 'request_id' => $requestId], $operation === 'create' ? 201 : 200, [
                ...$headers, ...(array_key_exists('idempotent_replay', $result)
                    ? ['Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false'] : []),
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2AgencyException|V2ReportingException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status,
                'code' => $exception->errorCode, 'request_id' => $requestId, 'retryable' => $exception->retryable,
            ], $exception->status, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
