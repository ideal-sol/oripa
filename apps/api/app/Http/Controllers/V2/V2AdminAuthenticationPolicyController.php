<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminAuthenticationPolicyService;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminAuthenticationPolicyController
{
    public function __construct(
        private readonly V2AdminAuthenticationPolicyService $policy,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $context = $this->authorization->context($request, $requestId);

        return $this->response([
            'data' => $this->policy->read($context),
            'request_id' => $requestId,
        ], 200, $requestId);
    }

    public function update(Request $request): JsonResponse
    {
        $this->assertExactFields($request, [
            'expected_revision',
            'mfa_required',
            'invitation_required',
            'current_password',
        ]);
        $requestId = $this->requestId($request);
        $context = $this->authorization->context($request, $requestId);
        $currentPassword = $request->input('current_password');
        if (! is_string($currentPassword) || $currentPassword === '') {
            throw $this->invalid();
        }
        $result = $this->policy->update(
            $context,
            $this->idempotencyKey($request),
            $request->only(['expected_revision', 'mfa_required', 'invitation_required']),
            $currentPassword
        );

        return $this->response([
            ...$result,
            'request_id' => $requestId,
        ], 200, $requestId);
    }

    public function createAdmin(Request $request): JsonResponse
    {
        $this->assertExactFields($request, ['email', 'role', 'temporary_password']);
        $requestId = $this->requestId($request);
        $context = $this->authorization->context($request, $requestId);
        $data = $this->policy->createAdmin($context, $request->all());

        return $this->response([
            'data' => $data,
            'request_id' => $requestId,
        ], 201, $requestId);
    }

    /** @param list<string> $allowed */
    private function assertExactFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
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
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid7();
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status, string $requestId): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function invalid(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'ADMIN_AUTHENTICATION_POLICY_INVALID',
            422,
            'The authentication policy request is invalid.'
        );
    }
}
