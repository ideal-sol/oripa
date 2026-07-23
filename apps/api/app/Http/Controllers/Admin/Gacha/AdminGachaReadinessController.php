<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Gacha\Services\GachaReadinessService;
use App\Http\Resources\AdminGachaReadinessResource;
use App\Models\Gacha;
use Illuminate\Routing\Controller;

class AdminGachaReadinessController extends Controller
{
    public function show(Gacha $gacha, GachaReadinessService $readinessService): AdminGachaReadinessResource
    {
        return new AdminGachaReadinessResource($readinessService->inspect($gacha));
    }
}
