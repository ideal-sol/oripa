<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\IndexUserPrizeRequest;
use App\Http\Resources\UserPrizeResource;
use App\Models\UserPrize;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class UserPrizeController extends Controller
{
    public function index(IndexUserPrizeRequest $request): AnonymousResourceCollection
    {
        $query = UserPrize::query()
            ->with(['gacha', 'prize.rank.rankImageAsset', 'prize.rank.rankImageAssets', 'prize.rank.drawVideoAsset', 'prize.rank.drawVideoAssets'])
            ->where('user_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return UserPrizeResource::collection(
            $query
                ->orderByDesc('acquired_at')
                ->orderByDesc('id')
                ->paginate((int) $request->integer('per_page', 20))
        );
    }
}
