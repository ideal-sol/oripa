<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Domain\Admin\Services\SalesManagementReportService;
use App\Http\Requests\Admin\SalesDailyRequest;
use App\Http\Requests\Admin\SalesMonthlyRequest;
use App\Models\DrawRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AdminSalesManagementController extends Controller
{
    public function monthly(SalesMonthlyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->monthlySales(
                year: (int) $request->integer('year'),
                month: (int) $request->integer('month'),
            ),
        ]);
    }

    public function dailyPayments(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyPayments(
            date: $request->string('date')->toString(),
            perPage: $request->perPage(),
        ));
    }

    public function dailyAdjustments(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyAdjustments(
            date: $request->string('date')->toString(),
        ));
    }

    public function monthlyPointConsumption(SalesMonthlyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->monthlyPointConsumption(
                year: (int) $request->integer('year'),
                month: (int) $request->integer('month'),
            ),
        ]);
    }

    public function dailyPointConsumption(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyPointConsumption(
            date: $request->string('date')->toString(),
            perPage: $request->perPage(),
        ));
    }

    public function drawRequest(DrawRequest $drawRequest, SalesManagementReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->drawRequestDetail($drawRequest),
        ]);
    }
}
