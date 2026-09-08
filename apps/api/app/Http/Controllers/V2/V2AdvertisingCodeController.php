<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Services\V2AgencyAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class V2AdvertisingCodeController
{
    public function __invoke(Request $request, V2AgencyAttributionService $attributions): JsonResponse
    {
        return response()->json(['valid' => $attributions->isValid($request->query('advertising_code'))])
            ->header('Cache-Control', 'no-store');
    }
}
