<?php

namespace App\Http\Controllers\Admin\Draw;

use App\Http\Resources\AdminDrawResultResource;
use App\Models\DrawResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminDrawResultController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DrawResult::query()
            ->with(['user', 'gacha', 'rank', 'prize', 'userPrize', 'probabilityVersion', 'probabilityVersionStage'])
            ->orderByDesc('id');

        if ($request->filled('result_type')) {
            $query->where('result_type', $request->string('result_type')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('gacha_id')) {
            $query->where('gacha_id', (int) $request->input('gacha_id'));
        }

        if ($request->filled('draw_request_id')) {
            $query->where('draw_request_id', (int) $request->input('draw_request_id'));
        }

        if ($request->filled('prize_id')) {
            $query->where('prize_id', (int) $request->input('prize_id'));
        }

        return AdminDrawResultResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(DrawResult $drawResult): AdminDrawResultResource
    {
        return new AdminDrawResultResource($drawResult->load([
            'user',
            'gacha',
            'rank',
            'prize',
            'userPrize',
            'probabilityVersion',
            'probabilityVersionStage',
        ]));
    }
}
