<?php

namespace App\Http\Controllers\V2;

use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class V2PrizeShippingController
{
    public function __construct(private readonly V2PrizeShippingService $service)
    {
    }

    public function prizes(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->prizes(
            $this->user(),
            $request->query('cursor'),
            (int) $request->query('limit', config('v2_prize_shipping.cursor_page_size', 20))
        ));
    }

    public function prize(Request $request, string $prizeId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->service->prizeDetail($this->user(), $prizeId)
        );
    }

    public function exchange(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request): array {
            $key = $request->header('Idempotency-Key');
            $ids = $request->input('prize_ids');
            if (! is_string($key) || ! is_array($ids)) {
                throw new V2PrizeShippingException(
                    'INVALID_EXCHANGE_REQUEST',
                    422,
                    'The Prize Exchange request is invalid.'
                );
            }

            return $this->service->exchange(
                $this->user(),
                array_values($ids),
                $key,
                $this->requestId($request)
            );
        });
    }

    public function addresses(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->service->addresses($this->user())
        );
    }

    public function address(Request $request, string $addressId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->addressDetail(
            $this->user(),
            $addressId,
            $this->requestId($request)
        ));
    }

    public function createAddress(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->createAddress(
            $this->user(),
            $request->only($this->addressFields()),
            $this->requestId($request)
        ), 201);
    }

    public function updateAddress(Request $request, string $addressId): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->updateAddress(
            $this->user(),
            $addressId,
            $request->only($this->addressFields()),
            $this->requestId($request)
        ));
    }

    public function deleteAddress(Request $request, string $addressId): JsonResponse
    {
        return $this->handle($request, function () use ($request, $addressId): array {
            $this->service->deleteAddress(
                $this->user(),
                $addressId,
                $this->requestId($request)
            );

            return ['deleted' => true];
        });
    }

    public function shippingRequests(Request $request): JsonResponse
    {
        return $this->handle($request, fn (): array => $this->service->shippingRequests(
            $this->user(),
            $request->query('cursor'),
            (int) $request->query('limit', config('v2_prize_shipping.cursor_page_size', 20))
        ));
    }

    public function shippingRequest(Request $request, string $shippingRequestId): JsonResponse
    {
        return $this->handle(
            $request,
            fn (): array => $this->service->shippingDetail(
                $this->user(),
                $shippingRequestId,
                $this->requestId($request)
            )
        );
    }

    public function createShippingRequest(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request): array {
            $key = $request->header('Idempotency-Key');
            $addressId = $request->input('shipping_address_id');
            $ids = $request->input('prize_ids');
            if (! is_string($key) || ! is_string($addressId) || ! is_array($ids)) {
                throw new V2PrizeShippingException(
                    'INVALID_SHIPPING_REQUEST',
                    422,
                    'The Shipping request is invalid.'
                );
            }

            return $this->service->createShippingRequest(
                $this->user(),
                $addressId,
                array_values($ids),
                $key,
                $this->requestId($request)
            );
        }, 201);
    }

    private function user(): User
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2PrizeShippingException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        return $user;
    }

    private function handle(Request $request, callable $callback, int $status = 200): JsonResponse
    {
        $requestId = $this->requestId($request);
        try {
            return response()->json($callback(), $status, $this->headers($requestId));
        } catch (V2PrizeShippingException $exception) {
            return $this->problem($exception, $requestId);
        }
    }

    private function problem(
        V2PrizeShippingException $exception,
        string $requestId
    ): JsonResponse {
        return response()->json([
            'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
            'title' => $exception->getMessage(),
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'request_id' => $requestId,
            'retryable' => $exception->retryable,
        ], $exception->status, [
            ...$this->headers($requestId),
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $requestId): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
            'X-Oripa-Api-Version' => '2',
        ];
    }

    private function requestId(Request $request): string
    {
        $value = $request->header('X-Request-Id');

        return is_string($value) && Str::isUuid($value)
            ? $value
            : (string) Str::uuid7();
    }

    /** @return list<string> */
    private function addressFields(): array
    {
        return [
            'recipient_name',
            'postal_code',
            'prefecture',
            'city',
            'street',
            'building',
            'phone_number',
        ];
    }
}
