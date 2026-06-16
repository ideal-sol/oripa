<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentPointGrantService;
use App\Domain\Payment\Services\PaymentIntentService;
use App\Http\Requests\Api\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PointPurchasePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, PaymentIntentService $service): JsonResponse
    {
        $plan = $request->filled('point_purchase_plan_id')
            ? PointPurchasePlan::query()
                ->where('is_active', true)
                ->findOrFail((int) $request->integer('point_purchase_plan_id'))
            : null;

        $payment = $service->create(
            user: $request->user(),
            amount: $plan?->amount ?? (int) $request->integer('amount'),
            paidPointAmount: $plan?->paid_point_amount ?? (int) $request->integer('paid_point_amount'),
            freePointAmount: $plan?->free_point_amount ?? (int) $request->integer('free_point_amount', 0),
            provider: $request->string('provider', 'mock')->toString(),
            currency: $request->string('currency', 'JPY')->toString(),
            metadata: [
                'terms_accepted' => true,
                'point_purchase_plan_id' => $plan?->id,
            ],
        );

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function mockSucceed(Request $request, Payment $payment, PaymentPointGrantService $service): PaymentResource|JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'Mock payment confirmation is only available in local environments.',
            ], 403);
        }

        if ((int) $payment->user_id !== (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'payment' => ['Payment was not found.'],
            ]);
        }

        if ($payment->provider !== 'mock') {
            throw ValidationException::withMessages([
                'payment' => ['Only mock payments can be confirmed this way.'],
            ]);
        }

        if ($payment->status !== PaymentStatus::Pending && $payment->status !== PaymentStatus::Succeeded) {
            throw ValidationException::withMessages([
                'payment' => ['Only pending payments can be confirmed.'],
            ]);
        }

        $payment = $service->markSucceeded(
            payment: $payment,
            webhookEventId: $payment->webhook_event_id ?? 'mock_checkout_'.Str::uuid()->toString(),
        );

        return new PaymentResource($payment);
    }
}
