<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shipping\Exceptions\UserPrizeOperationException;
use App\Domain\Shipping\Services\UserPrizeExchangeService;
use App\Http\Resources\UserPrizeResource;
use App\Models\UserPrize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class UserPrizeExchangeController extends Controller
{
    public function store(Request $request, UserPrize $userPrize, UserPrizeExchangeService $service): UserPrizeResource|JsonResponse
    {
        try {
            return new UserPrizeResource($service->exchange($request->user(), $userPrize));
        } catch (UserPrizeOperationException $exception) {
            throw ValidationException::withMessages([
                'user_prize' => [$exception->getMessage()],
            ]);
        }
    }
}
