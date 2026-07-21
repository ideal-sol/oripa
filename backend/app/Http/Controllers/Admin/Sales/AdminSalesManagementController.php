<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Domain\Admin\Services\SalesManagementReportService;
use App\Domain\Admin\Services\SalesManagementCsvService;
use App\Http\Requests\Admin\SalesDailyRequest;
use App\Http\Requests\Admin\SalesMonthlyRequest;
use App\Models\DrawRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

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

    public function monthlyCsv(SalesMonthlyRequest $request, SalesManagementCsvService $service): Response
    {
        $year = (int) $request->integer('year');
        $month = (int) $request->integer('month');

        return $this->csvResponse(
            $service->monthlySales($year, $month),
            sprintf('sales_monthly_%04d-%02d.csv', $year, $month),
        );
    }

    public function dailyPayments(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyPayments(
            date: $request->string('date')->toString(),
            perPage: $request->perPage(),
        ));
    }

    public function dailyPaymentsCsv(SalesDailyRequest $request, SalesManagementCsvService $service): Response
    {
        $date = $request->string('date')->toString();

        return $this->csvResponse(
            $service->dailyPayments($date),
            sprintf('sales_daily_payments_%s.csv', $date),
        );
    }

    public function dailyAdjustments(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyAdjustments(
            date: $request->string('date')->toString(),
        ));
    }

    public function dailyAdjustmentsCsv(SalesDailyRequest $request, SalesManagementCsvService $service): Response
    {
        $date = $request->string('date')->toString();

        return $this->csvResponse(
            $service->dailyAdjustments($date),
            sprintf('sales_daily_adjustments_%s.csv', $date),
        );
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

    public function monthlyPointConsumptionCsv(SalesMonthlyRequest $request, SalesManagementCsvService $service): Response
    {
        $year = (int) $request->integer('year');
        $month = (int) $request->integer('month');

        return $this->csvResponse(
            $service->monthlyPointConsumption($year, $month),
            sprintf('sales_monthly_point_consumption_%04d-%02d.csv', $year, $month),
        );
    }

    public function dailyPointConsumption(SalesDailyRequest $request, SalesManagementReportService $service): JsonResponse
    {
        return response()->json($service->dailyPointConsumption(
            date: $request->string('date')->toString(),
            perPage: $request->perPage(),
        ));
    }

    public function dailyPointConsumptionCsv(SalesDailyRequest $request, SalesManagementCsvService $service): Response
    {
        $date = $request->string('date')->toString();

        return $this->csvResponse(
            $service->dailyPointConsumption($date),
            sprintf('sales_daily_point_consumption_%s.csv', $date),
        );
    }

    public function drawRequest(DrawRequest $drawRequest, SalesManagementReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->drawRequestDetail($drawRequest),
        ]);
    }

    private function csvResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
