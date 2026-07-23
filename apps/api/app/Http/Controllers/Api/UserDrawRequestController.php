<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DrawRequestResource;
use App\Models\DrawRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class UserDrawRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DrawRequestResource::collection(
            DrawRequest::query()
                ->with(['gacha:id,title,slug,price,main_image_url', 'results.prize', 'results.rank.rankImageAsset', 'results.rank.rankImageAssets', 'results.rank.drawVideoAsset', 'results.rank.drawVideoAssets'])
                ->withCount('results')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->paginate((int) $request->integer('per_page', 20))
        );
    }

    public function show(Request $request, DrawRequest $drawRequest): DrawRequestResource
    {
        abort_unless((int) $drawRequest->user_id === (int) $request->user()->id, 404);

        return new DrawRequestResource(
            $drawRequest->load(['gacha:id,title,slug,price,main_image_url', 'results.prize', 'results.rank.rankImageAsset', 'results.rank.rankImageAssets', 'results.rank.drawVideoAsset', 'results.rank.drawVideoAssets'])
                ->loadCount('results')
        );
    }
}
