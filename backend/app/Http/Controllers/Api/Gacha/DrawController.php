<?php

namespace App\Http\Controllers\Api\Gacha;

use App\Domain\Gacha\Exceptions\DrawException;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Http\Requests\Api\Gacha\DrawRequest as DrawFormRequest;
use App\Http\Resources\DrawRequestResource;
use App\Models\Gacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class DrawController extends Controller
{
    public function store(DrawFormRequest $request, Gacha $gacha, DrawService $drawService): DrawRequestResource|JsonResponse
    {
        try {
            $drawRequest = $drawService->draw(
                user: $request->user(),
                gacha: $gacha,
                drawCount: $request->drawCount(),
                idempotencyKey: $request->idempotencyKey(),
            );
        } catch (InsufficientPointsException $exception) {
            throw ValidationException::withMessages([
                'points' => [$exception->getMessage()],
            ]);
        } catch (DrawException $exception) {
            throw ValidationException::withMessages([
                'draw' => [$exception->getMessage()],
            ]);
        }

        return (new DrawRequestResource($drawRequest->loadMissing(['results.prize', 'results.rank'])))
            ->response()
            ->setStatusCode(201);
    }
}
