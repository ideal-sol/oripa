<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PointPurchasePlanResource;
use App\Models\PointPurchasePlan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PointPurchasePlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PointPurchasePlanResource::collection(
            PointPurchasePlan::query()
                ->currentlyAvailable()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }
}
