<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shipping\Exceptions\UserPrizeOperationException;
use App\Domain\Shipping\Services\ShippingRequestService;
use App\Http\Requests\Api\StoreShippingRequestRequest;
use App\Http\Resources\ShippingRequestResource;
use App\Models\ShippingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class ShippingRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ShippingRequestResource::collection(
            ShippingRequest::query()
                ->with(['items.userPrize.gacha', 'items.userPrize.prize.rank'])
                ->withCount('items')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->paginate((int) $request->integer('per_page', 20))
        );
    }

    public function show(Request $request, ShippingRequest $shippingRequest): ShippingRequestResource
    {
        abort_unless((int) $shippingRequest->user_id === (int) $request->user()->id, 404);

        return new ShippingRequestResource(
            $shippingRequest->load(['items.userPrize.gacha', 'items.userPrize.prize.rank', 'histories'])
                ->loadCount('items')
        );
    }

    public function store(StoreShippingRequestRequest $request, ShippingRequestService $service): ShippingRequestResource|JsonResponse
    {
        try {
            $shippingRequest = $service->create(
                user: $request->user(),
                userPrizeIds: $request->collect('user_prize_ids')->map(fn ($id): int => (int) $id)->all(),
                address: $request->safe()->except('user_prize_ids'),
            );
        } catch (UserPrizeOperationException $exception) {
            throw ValidationException::withMessages([
                'user_prize_ids' => [$exception->getMessage()],
            ]);
        }

        return (new ShippingRequestResource($shippingRequest))
            ->response()
            ->setStatusCode(201);
    }
}
