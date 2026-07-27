<?php

namespace App\Http\Controllers\Api\Gacha;

use App\Domain\Gacha\Exceptions\DrawException;
use App\Domain\Gacha\Exceptions\BulkDrawConflictException;
use App\Domain\Gacha\Services\BulkDrawSummaryService;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Http\Requests\Api\Gacha\DrawRequest as DrawFormRequest;
use App\Http\Resources\BulkDrawRequestResource;
use App\Http\Resources\DrawRequestResource;
use App\Models\Gacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class DrawController extends Controller
{
    public function store(
        DrawFormRequest $request,
        Gacha $gacha,
        DrawService $drawService,
        BulkDrawSummaryService $bulkDrawSummaryService,
    ): DrawRequestResource|BulkDrawRequestResource|JsonResponse
    {
        try {
            $drawRequest = $drawService->draw(
                user: $request->user(),
                gacha: $gacha,
                drawCount: $request->drawCount(),
                idempotencyKey: $request->idempotencyKey(),
            );
        } catch (BulkDrawConflictException $exception) {
            return response()->json([
                'message' => 'Bulk draw request conflicts with an existing idempotency record.',
                'errors' => ['idempotency_key' => [$exception->getMessage()]],
            ], 409);
        } catch (InsufficientPointsException $exception) {
            throw ValidationException::withMessages([
                'points' => [$exception->getMessage()],
            ]);
        } catch (DrawException $exception) {
            throw ValidationException::withMessages([
                'draw' => [$exception->getMessage()],
            ]);
        }

        if ($drawRequest->isBulk()) {
            $drawRequest->bulkSummary = $bulkDrawSummaryService->build($drawRequest);

            return (new BulkDrawRequestResource($drawRequest))
                ->response()
                ->setStatusCode($drawRequest->idempotentReplay ? 200 : 201);
        }

        return (new DrawRequestResource($drawRequest->loadMissing(['results.prize', 'results.rank.rankImageAsset', 'results.rank.rankImageAssets', 'results.rank.drawVideoAsset', 'results.rank.drawVideoAssets'])))
            ->response()
            ->setStatusCode(201);
    }
}
