<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Resources\TopBannerResource;
use App\Models\TopBanner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class TopBannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TopBannerResource::collection(
            TopBanner::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }
}
