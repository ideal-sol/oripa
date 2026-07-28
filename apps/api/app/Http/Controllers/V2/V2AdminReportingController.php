<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\Services\V2ExportService;
use App\Domain\Reporting\Services\V2ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Str;

final class V2AdminReportingController
{
    public function __construct(
        private readonly V2ReportingService $reporting,
        private readonly V2ExportService $exports,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function monthlySales(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->monthlySales(
            $this->context($request),
            (string) $request->query('month')
        ));
    }

    public function dailySales(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->dailySales(
            $this->context($request),
            (string) $request->query('date'),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function adjustments(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->adjustments(
            $this->context($request),
            (string) $request->query('date'),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function points(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->pointSummary(
            $this->context($request),
            (string) $request->query('month')
        ));
    }

    public function gachas(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->gachaSummary(
            $this->context($request),
            (string) $request->query('month'),
            (string) $request->query('qa_filter', 'all')
        ));
    }

    public function draws(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->drawHistory(
            $this->context($request),
            (string) $request->query('date'),
            (string) $request->query('qa_filter', 'all'),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function drawResults(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->drawResultHistory(
            $this->context($request),
            (string) $request->query('date'),
            (string) $request->query('qa_filter', 'all'),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function snapshots(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->snapshots(
            $this->context($request),
            (string) $request->query('month'),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function snapshot(Request $request, string $date): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->reporting->snapshot(
            $this->context($request),
            $date
        ));
    }

    public function stream(Request $request): JsonResponse|StreamedResponse
    {
        return $this->handleStream($request, fn (): StreamedResponse => $this->exports->stream(
            $this->context($request),
            $request->all()
        ));
    }

    public function createJob(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request): array {
            $key = $request->header('Idempotency-Key');
            if (! is_string($key) || $key === '') {
                throw new V2ReportingException(
                    'IDEMPOTENCY_KEY_REQUIRED',
                    422,
                    'An Idempotency-Key is required.'
                );
            }

            return $this->exports->createJob(
                $this->context($request),
                $key,
                $request->all()
            );
        }, 202);
    }

    public function jobs(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->exports->jobs(
            $this->context($request),
            $this->stringQuery($request, 'cursor'),
            $this->limit($request)
        ));
    }

    public function job(Request $request, string $exportJobId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->exports->job(
            $this->context($request),
            $exportJobId
        ));
    }

    public function download(Request $request, string $exportJobId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->exports->download(
            $this->context($request),
            $exportJobId
        ));
    }

    public function file(Request $request, string $exportJobId): JsonResponse|StreamedResponse
    {
        return $this->handleStream($request, fn (): StreamedResponse => $this->exports->file(
            $this->context($request),
            $exportJobId
        ));
    }

    private function context(Request $request): V2AdminAuthorizationContext
    {
        return $this->authorization->context($request, $this->requestId($request));
    }

    private function handle(
        Request $request,
        callable $callback,
        int $status = 200
    ): JsonResponse {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), $status, $this->headers($requestId));
        } catch (
            V2ReportingException
            |V2AuthenticationException
            |V2PointException $exception
        ) {
            return $this->problem($requestId, $exception);
        }
    }

    private function handleStream(
        Request $request,
        callable $callback
    ): JsonResponse|StreamedResponse {
        $requestId = $this->requestId($request);
        try {
            return $callback();
        } catch (
            V2ReportingException
            |V2AuthenticationException
            |V2PointException $exception
        ) {
            return $this->problem($requestId, $exception);
        }
    }

    private function problem(
        string $requestId,
        V2ReportingException|V2AuthenticationException|V2PointException $exception
    ): JsonResponse {
        if ($exception instanceof V2PointException) {
            [$code, $status, $message] = match ($exception->getMessage()) {
                'IDEMPOTENCY_KEY_REUSED' => [
                    'IDEMPOTENCY_KEY_CONFLICT',
                    409,
                    'The Idempotency-Key was used for a different request.',
                ],
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => [
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The request is already being processed.',
                ],
                default => [
                    'REPORTING_OPERATION_FAILED',
                    500,
                    'The Reporting operation failed.',
                ],
            };
            $retryable = false;
            $retryAfter = null;
        } else {
            $code = $exception->errorCode;
            $status = $exception->status;
            $message = $exception->getMessage();
            $retryable = $exception->retryable;
            $retryAfter = $exception->retryAfter;
        }
        $headers = [
            ...$this->headers($requestId),
            'Content-Type' => 'application/problem+json',
        ];
        if (is_int($retryAfter)) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($code),
            'title' => $message,
            'status' => $status,
            'code' => $code,
            'request_id' => $requestId,
            'retryable' => $retryable,
        ], $status, $headers);
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
        $existing = $request->attributes->get('v2_reporting_request_id');
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }
        $header = $request->header('X-Request-Id');
        $requestId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid7();
        $request->attributes->set('v2_reporting_request_id', $requestId);

        return $requestId;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function limit(Request $request): int
    {
        $value = $request->query('limit', config('v2_reporting.pagination.default'));

        return is_numeric($value) ? (int) $value : 0;
    }
}
