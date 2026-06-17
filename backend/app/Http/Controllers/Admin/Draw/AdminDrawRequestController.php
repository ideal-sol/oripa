<?php

namespace App\Http\Controllers\Admin\Draw;

use App\Http\Resources\AdminDrawRequestResource;
use App\Models\DrawRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminDrawRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DrawRequest::query()
            ->with(['user', 'gacha'])
            ->withCount('results')
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

        return AdminDrawRequestResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(DrawRequest $drawRequest): AdminDrawRequestResource
    {
        return new AdminDrawRequestResource($drawRequest->load([
            'user',
            'gacha',
            'results.user',
            'results.gacha',
            'results.rank.rankImageAsset',
            'results.rank.drawVideoAsset',
            'results.prize',
            'results.userPrize',
            'results.probabilityVersion',
            'results.probabilityVersionStage',
        ]));
    }
}
