<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Payment\V2\Services\V2AdminPaymentReadService;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminPaymentController
{
    public function __construct(
        private readonly V2AdminPaymentReadService $payments,
        private readonly V2AdminPaymentAdjustmentService $adjustments,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->payments->all(
            $this->context($request),
            $this->query($request, 'cursor'),
            $this->limit($request),
            $this->query($request, 'status'),
            $this->query($request, 'payment_method'),
            $this->query($request, 'user_id'),
            $this->query($request, 'provider'),
            $this->query($request, 'progress'),
            $this->query($request, 'from'),
            $this->query($request, 'until')
        ));
    }

    public function show(Request $request, string $paymentId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->adjustments->detail($this->context($request), $paymentId));
    }

    public function preview(Request $request, string $paymentId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->adjustments->preview($this->context($request), $paymentId, $request->json()->all()));
    }

    public function write(Request $request, string $paymentId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->adjustments->write(
            $this->context($request), $paymentId, $request->route()->defaults['operation'],
            $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $request->route('adjustmentId')
        ));
    }

    public function userHistory(Request $request, string $userId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->payments->forUser(
            $this->context($request),
            $userId,
            $this->query($request, 'cursor'),
            $this->limit($request),
            $this->query($request, 'status'),
            $this->query($request, 'payment_method')
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
            $result = $callback();

            return response()->json($result, 200, [...$this->headers($requestId),
                ...(isset($result['idempotent_replay']) ? ['Idempotency-Replayed' => $result['idempotent_replay'] ? 'true' : 'false'] : [])]);
        } catch (V2ReportingException $exception) {
            return $this->problem($requestId, $exception->errorCode, $exception->status, $exception->getMessage(), $exception->retryable);
        } catch (V2AdminPaymentReadException $exception) {
            return $this->problem($requestId, $exception->errorCode, $exception->status, $exception->getMessage(), $exception->retryable);
        } catch (V2AuthenticationException $exception) {
            return $this->problem($requestId, $exception->errorCode, $exception->status, $exception->getMessage(), $exception->retryable);
        }
    }

    private function problem(
        string $requestId,
        string $code,
        int $status,
        string $message,
        bool $retryable
    ): JsonResponse {
        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($code),
            'title' => $message,
            'status' => $status,
            'code' => $code,
            'request_id' => $requestId,
            'retryable' => $retryable,
        ], $status, [...$this->headers($requestId), 'Content-Type' => 'application/problem+json']);
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function limit(Request $request): int
    {
        $value = $request->query('limit', 20);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, string> */
    private function headers(string $requestId): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2'];
    }

    private function requestId(Request $request): string
    {
        $existing = $request->attributes->get('v2_admin_payment_request_id');
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }
        $header = $request->header('X-Request-Id');
        $requestId = is_string($header) && Str::isUuid($header) ? $header : (string) Str::uuid7();
        $request->attributes->set('v2_admin_payment_request_id', $requestId);

        return $requestId;
    }
}
