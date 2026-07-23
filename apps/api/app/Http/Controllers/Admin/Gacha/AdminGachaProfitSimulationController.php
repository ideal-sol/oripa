<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Gacha\Services\GachaProfitSimulationService;
use App\Models\Gacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AdminGachaProfitSimulationController extends Controller
{
    public function show(Gacha $gacha, GachaProfitSimulationService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->simulate($gacha),
        ]);
    }
}
