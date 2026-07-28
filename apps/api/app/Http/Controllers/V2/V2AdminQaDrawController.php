<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminQaDrawController
{
    public function __construct(
        private readonly V2QaDrawAdminService $service,
        private readonly V2AdminFreshMfaAuthorizer $freshMfa
    ) {
    }

    public function showMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->mode($this->context($request), $userId));
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
                $this->context($request),
                $userId,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                $endsAt
            );
        });
    }

    public function disableMode(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->disableMode(
            $this->context($request),
            $userId
        ));
    }

    public function plans(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plans($this->context($request), $userId));
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
                $this->context($request),
                $userId,
                $gachaId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null,
                $items
            );
        });
    }

    public function showPlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array =>
            $this->service->plan($this->context($request), $planId));
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
                $this->context($request),
                $planId,
                $title,
                $reason,
                is_string($request->input('starts_at')) ? $request->input('starts_at') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null
            );
        });
    }

    public function pausePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->pausePlan(
            $this->context($request),
            $planId
        ));
    }

    public function activatePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->activatePlan(
            $this->context($request),
            $planId
        ));
    }

    public function disablePlan(Request $request, string $planId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->disablePlan(
            $this->context($request),
            $planId
        ));
    }

    public function executions(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->executions(
            $this->context($request),
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
            $this->context($request),
            $executionId
        ));
    }

    private function context(Request $request): V2AdminAuthorizationContext
    {
        return $this->freshMfa->context($request, $this->requestId($request));
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
        } catch (V2QaDrawException|V2AuthenticationException $exception) {
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
