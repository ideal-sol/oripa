<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PointLotResource;
use App\Http\Resources\WalletResource;
use App\Models\PointLot;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PointController extends Controller
{
    public function index(Request $request): array
    {
        $wallet = Wallet::query()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['paid_balance' => 0, 'free_balance' => 0],
        );

        $lots = PointLot::query()
            ->where('user_id', $request->user()->id)
            ->where('remaining_amount', '>', 0)
            ->orderByRaw("CASE WHEN point_type = 'free' THEN 0 ELSE 1 END")
            ->orderBy('expire_at')
            ->orderBy('granted_at')
            ->get();

        return [
            'wallet' => new WalletResource($wallet),
            'lots' => PointLotResource::collection($lots),
        ];
    }
}
