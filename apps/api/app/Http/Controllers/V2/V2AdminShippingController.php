<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Models\V2\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2AdminShippingController
{
    public function __construct(
        private readonly V2PrizeShippingService $service,
        private readonly V2PermissionAuthorizer $permissions
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->adminShippingRequests(
            $request->query('cursor'),
            (int) $request->query('limit', config('v2_prize_shipping.cursor_page_size', 20))
        ));
    }

    public function show(Request $request, string $shippingRequestId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->adminShippingDetail(
            $this->admin(),
            $shippingRequestId,
            $this->requestId($request)
        ));
    }

    public function update(Request $request, string $shippingRequestId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $shippingRequestId): array {
            $status = $request->input('status');
            if (! is_string($status)) {
                throw new V2PrizeShippingException(
                    'INVALID_SHIPPING_TRANSITION',
                    422,
                    'The Shipping transition is invalid.'
                );
            }

            return $this->service->transitionShipping(
                $this->admin(),
                $shippingRequestId,
                $status,
                is_string($request->input('carrier_code'))
                    ? $request->input('carrier_code')
                    : null,
                is_string($request->input('tracking_number'))
                    ? $request->input('tracking_number')
                    : null,
                is_string($request->input('reason')) ? $request->input('reason') : null,
                $this->requestId($request)
            );
        });
    }

    private function admin(): Admin
    {
        $admin = Auth::guard('v2_admin')->user();
        if (
            ! $admin instanceof Admin
            || ! $this->permissions->allows(
                $admin->role,
                V2Permission::ManageShippingRequest
            )
        ) {
            throw new V2PrizeShippingException(
                'AUTHORIZATION_DENIED',
                403,
                'The operation is not authorized.'
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
        } catch (V2PrizeShippingException $exception) {
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
}
