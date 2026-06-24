<?php

namespace App\Http\Controllers\Api\Gacha;

use App\Http\Resources\GachaTagResource;
use App\Models\GachaTag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class GachaTagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GachaTagResource::collection(
            GachaTag::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }
}
