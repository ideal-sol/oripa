<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Services\ChargebackReversalService;
use App\Domain\Payment\Services\PaymentRefundService;
use App\Domain\Payment\Services\RefundEligibilityService;
use App\Http\Requests\Admin\UpdatePaymentStatusRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PaymentReversalResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminPaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Payment::query()
            ->with('user')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        return PaymentResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load('user'));
    }

    public function refund(
        UpdatePaymentStatusRequest $request,
        Payment $payment,
        PaymentRefundService $paymentRefundService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        $before = $this->statusSnapshot($payment);

        try {
            $reversal = $paymentRefundService->refund($payment, $request->user(), $request->validated('reason'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['payment' => [$exception->getMessage()]]);
        }

        $payment = $reversal->payment;
        $this->recordStatusAudit($auditLogService, $request, $payment, 'admin.payment.refunded', $before);

        return (new PaymentReversalResource($reversal->load('payment.user', 'user', 'adminUser', 'pointEntries')))
            ->response()
            ->setStatusCode(200);
    }

    public function chargeback(
        UpdatePaymentStatusRequest $request,
        Payment $payment,
        ChargebackReversalService $chargebackReversalService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        $before = $this->statusSnapshot($payment);

        try {
            $reversal = $chargebackReversalService->chargeback($payment, $request->user(), $request->validated('reason'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['payment' => [$exception->getMessage()]]);
        }

        $payment = $reversal->payment;
        $this->recordStatusAudit($auditLogService, $request, $payment, 'admin.payment.chargeback', $before);

        return (new PaymentReversalResource($reversal->load('payment.user', 'user', 'adminUser', 'pointEntries', 'prizeActions')))
            ->response()
            ->setStatusCode(200);
    }

    public function refundEligibility(Payment $payment, RefundEligibilityService $refundEligibilityService): array
    {
        $result = $refundEligibilityService->check($payment);

        return [
            'data' => [
                'payment_id' => $payment->id,
                'eligible' => $result['eligible'],
                'reason' => $result['reason'],
                'used_amount' => $result['used_amount'],
                'refundable_amount' => $result['refundable_amount'],
            ],
        ];
    }
    private function statusSnapshot(Payment $payment): array
    {
        return [
            'status' => $payment->status?->value ?? $payment->status,
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'chargeback_at' => $payment->chargeback_at?->toIso8601String(),
        ];
    }

    private function recordStatusAudit(
        AuditLogService $auditLogService,
        Request $request,
        Payment $payment,
        string $action,
        array $before,
    ): void {
        $auditLogService->record(
            action: $action,
            adminUser: $request->user(),
            auditable: $payment,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $this->statusSnapshot($payment),
            ],
        );
    }
}
