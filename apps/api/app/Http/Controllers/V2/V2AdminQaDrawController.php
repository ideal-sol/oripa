<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Models\V2\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2AdminQaDrawController
{
    public function __construct(
        private readonly V2QaDrawAdminService $service,
        private readonly V2PermissionAuthorizer $permissions
    ) {
    }

    public function showMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->mode($this->admin(), $userId));
    }

    public function saveMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $userId): array {
            $reason = $request->input('reason');
            $endsAt = $request->input('ends_at');
            if (! is_string($reason) || ! is_string($endsAt)) {
                throw $this->invalid();
            }

            return $this->service->saveMode(
                $this->admin(),
                $userId,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                $endsAt,
                $this->requestId($request)
            );
        });
    }

    public function disableMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->disableMode(
            $this->admin(),
            $userId,
            $this->requestId($request)
        ));
    }

    public function plans(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plans($this->admin(), $userId));
    }

    public function createPlan(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $userId): array {
            $gachaId = $request->input('gacha_id');
            $title = $request->input('title');
            $reason = $request->input('reason');
            $items = $request->input('items');
            if (
                ! is_string($gachaId)
                || ! is_string($title)
                || ! is_string($reason)
                || ! is_array($items)
            ) {
                throw $this->invalid();
            }

            return $this->service->createPlan(
                $this->admin(),
                $userId,
                $gachaId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null,
                $items,
                $this->requestId($request)
            );
        });
    }

    public function showPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plan($this->admin(), $planId));
    }

    public function updatePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $planId): array {
            $title = $request->input('title');
            $reason = $request->input('reason');
            if (! is_string($title) || ! is_string($reason)) {
                throw $this->invalid();
            }

            return $this->service->updatePlan(
                $this->admin(),
                $planId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null,
                $this->requestId($request)
            );
        });
    }

    public function pausePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->pausePlan(
            $this->admin(),
            $planId,
            $this->requestId($request)
        ));
    }

    public function activatePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->activatePlan(
            $this->admin(),
            $planId,
            $this->requestId($request)
        ));
    }

    public function disablePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->disablePlan(
            $this->admin(),
            $planId,
            $this->requestId($request)
        ));
    }

    public function executions(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->executions(
            $this->admin(),
            $request->only([
                'user_id',
                'gacha_id',
                'draw_request_id',
                'from',
                'to',
                'cursor',
                'limit',
            ])
        ));
    }

    public function showExecution(Request $request, string $executionId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->execution(
            $this->admin(),
            $executionId,
            $this->requestId($request)
        ));
    }

    private function admin(): Admin
    {
        $admin = Auth::guard('v2_admin')->user();
        if (! $admin instanceof Admin) {
            throw new V2QaDrawException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }
        if (! $this->permissions->allows($admin->role, V2Permission::ManageQaDraw)) {
            throw new V2QaDrawException(
                'AUTHORIZATION_DENIED',
                403,
                'The QA Draw operation is restricted to Owners.'
            );
        }

        return $admin;
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), 200, [
                'Cache-Control' => 'no-store',
                'X-Request-Id' => $requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2QaDrawException $exception) {
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
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }

    private function invalid(): V2QaDrawException
    {
        return new V2QaDrawException(
            'QA_CONFIGURATION_INVALID',
            422,
            'The QA Draw request is invalid.'
        );
    }
}
