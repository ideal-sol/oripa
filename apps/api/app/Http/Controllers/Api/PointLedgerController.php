<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PointLedgerResource;
use App\Models\PointLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PointLedgerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PointLedgerResource::collection(
            PointLedger::query()
                ->with('pointLot')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->paginate((int) $request->integer('per_page', 20))
        );
    }
}
