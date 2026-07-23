<?php

namespace App\Http\Controllers\Api\Gacha;

use App\Domain\Gacha\Services\GachaDetailService;
use App\Domain\Gacha\Services\GachaListService;
use App\Http\Resources\GachaDetailResource;
use App\Http\Resources\GachaListResource;
use App\Models\Gacha;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class GachaController extends Controller
{
    public function index(GachaListService $service): AnonymousResourceCollection
    {
        return GachaListResource::collection($service->paginate());
    }

    public function show(Gacha $gacha, GachaDetailService $service): GachaDetailResource
    {
        return new GachaDetailResource($service->findForUser($gacha));
    }
}
