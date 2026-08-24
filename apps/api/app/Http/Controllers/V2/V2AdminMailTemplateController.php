<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Mail\Exceptions\V2MailTemplateException;
use App\Domain\Mail\Services\V2MailTemplateService;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminMailTemplateController
{
    public function __construct(
        private readonly V2MailTemplateService $service,
        private readonly V2AdminFreshMfaAuthorizer $authorizer
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->templates($context));
    }

    public function show(Request $request, string $templateKey): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->template($context, $templateKey));
    }

    public function update(Request $request, string $templateKey): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->update(
                $context,
                $templateKey,
                $request->all(),
                (string) $request->header('Idempotency-Key', '')
            ));
    }

    public function preview(Request $request, string $templateKey): JsonResponse
    {
        return $this->handle($request, fn (V2AdminAuthorizationContext $context): array =>
            $this->service->preview($context, $templateKey, $request->all()));
    }

    /** @param callable(V2AdminAuthorizationContext): array<string, mixed> $callback */
    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            $context = $this->authorizer->context($request, $this->requestId($request));

            return response()->json($callback($context), 200, [
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $context->requestId,
                'X-Oripa-Api-Version' => '2',
            ]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2MailTemplateException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $this->requestId($request),
                'retryable' => $exception->retryable,
            ], $exception->status, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'private, no-store',
                'X-Request-Id' => $this->requestId($request),
                'X-Oripa-Api-Version' => '2',
            ]);
        }
    }

    private function requestId(Request $request): string
    {
        $stored = $request->attributes->get('v2_request_id');
        if (is_string($stored) && Str::isUuid($stored)) {
            return $stored;
        }
        $value = $request->header('X-Request-Id');
        $requestId = is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
        $request->attributes->set('v2_request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);

        return $requestId;
    }
}
