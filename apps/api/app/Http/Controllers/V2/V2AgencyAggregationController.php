<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\Services\V2AgencyAggregationService;
use App\Http\Responses\V2ProblemDetails;
use App\Models\V2\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AgencyAggregationController
{
    public function __construct(
        private readonly V2AgencyAggregationService $aggregation,
        private readonly V2AdminFreshMfaAuthorizer $authorization
    ) {
    }

    public function adminUsers(Request $request): JsonResponse
    {
        return $this->respond($request, 'users', true);
    }

    public function adminSales(Request $request): JsonResponse
    {
        return $this->respond($request, 'sales', true);
    }

    public function agencyUsers(Request $request): JsonResponse
    {
        return $this->respond($request, 'users', false);
    }

    public function agencySales(Request $request): JsonResponse
    {
        return $this->respond($request, 'sales', false);
    }

    private function respond(Request $request, string $kind, bool $admin): JsonResponse
    {
        $requestId = (string) Str::uuid7();
        $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie',
            'X-Request-Id' => $requestId, 'X-Oripa-Api-Version' => '2', 'X-Robots-Tag' => 'noindex, nofollow'];
        try {
            if ($admin) {
                $context = $this->authorization->context($request, $requestId);
            } else {
                $agency = $request->user('v2_agency');
                if (! $agency instanceof Agency) {
                    throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
                }
            }
            if ($request->getContent() !== '') {
                throw new V2ReportingException('REPORTING_PERIOD_INVALID', 422, 'A Reporting request cannot have a body.');
            }
            $result = $admin ? $this->aggregation->admin($context, $kind, $request->query())
                : $this->aggregation->agency($agency, $kind, $request->query());

            return response()->json($result, 200, $headers);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (V2ReportingException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(), 'status' => $exception->status,
                'code' => $exception->errorCode, 'request_id' => $requestId, 'retryable' => $exception->retryable,
            ], $exception->status, [...$headers, 'Content-Type' => 'application/problem+json']);
        }
    }
}
