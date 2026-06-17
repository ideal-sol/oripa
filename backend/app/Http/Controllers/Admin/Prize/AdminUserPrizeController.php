<?php

namespace App\Http\Controllers\Admin\Prize;

use App\Http\Resources\AdminUserPrizeResource;
use App\Models\UserPrize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminUserPrizeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = UserPrize::query()
            ->with(['user', 'gacha', 'prize.rank.rankImageAsset', 'prize.rank.drawVideoAsset'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('gacha_id')) {
            $query->where('gacha_id', (int) $request->input('gacha_id'));
        }

        if ($request->filled('gacha_prize_id')) {
            $query->where('gacha_prize_id', (int) $request->input('gacha_prize_id'));
        }

        return AdminUserPrizeResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(UserPrize $userPrize): AdminUserPrizeResource
    {
        return new AdminUserPrizeResource($userPrize->load([
            'user',
            'gacha',
            'prize.rank',
            'drawResult.user',
            'drawResult.gacha',
            'drawResult.rank',
            'drawResult.prize',
            'drawResult.probabilityVersion',
            'drawResult.probabilityVersionStage',
        ]));
    }
}
