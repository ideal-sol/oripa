<?php

namespace App\Http\Controllers\Admin\Point;

use App\Http\Requests\Admin\IndexPointBalanceSnapshotRequest;
use App\Http\Requests\Admin\PointBalanceSnapshotBaseDateRequest;
use App\Http\Resources\PointBalanceSnapshotResource;
use App\Models\PointBalanceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AdminPointBalanceSnapshotController extends Controller
{
    public function latest(): JsonResponse
    {
        $snapshot = PointBalanceSnapshot::query()
            ->orderByDesc('snapshot_date')
            ->first();

        return response()->json([
            'data' => $snapshot ? PointBalanceSnapshotResource::make($snapshot) : null,
        ]);
    }

    public function index(IndexPointBalanceSnapshotRequest $request): JsonResponse
    {
        $query = PointBalanceSnapshot::query()
            ->when($request->filled('date_from'), fn ($query) => $query->where('snapshot_date', '>=', $request->string('date_from')->toString()))
            ->when($request->filled('date_to'), fn ($query) => $query->where('snapshot_date', '<=', $request->string('date_to')->toString()))
            ->orderByDesc('snapshot_date');

        $paginator = $query->paginate($request->perPage());

        return response()->json([
            'data' => PointBalanceSnapshotResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function baseDates(PointBalanceSnapshotBaseDateRequest $request): JsonResponse
    {
        $year = (int) $request->integer('year');
        $dates = [
            CarbonImmutable::create($year, 3, 31, 0, 0, 0, 'Asia/Tokyo')->toDateString(),
            CarbonImmutable::create($year, 9, 30, 0, 0, 0, 'Asia/Tokyo')->toDateString(),
        ];

        $snapshots = PointBalanceSnapshot::query()
            ->whereIn('snapshot_date', $dates)
            ->get()
            ->keyBy(fn (PointBalanceSnapshot $snapshot): string => $snapshot->snapshot_date->toDateString());

        return response()->json([
            'data' => collect($dates)
                ->map(fn (string $date): array => [
                    'date' => $date,
                    'exists' => $snapshots->has($date),
                    'snapshot' => $snapshots->has($date)
                        ? PointBalanceSnapshotResource::make($snapshots->get($date))->resolve()
                        : null,
                ])
                ->values()
                ->all(),
        ]);
    }
}
