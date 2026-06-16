<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Exceptions\PaymentOperationException;
use App\Domain\Payment\Services\PaymentStatusService;
use App\Http\Requests\Admin\UpdatePaymentStatusRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

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
        PaymentStatusService $paymentStatusService,
        AuditLogService $auditLogService,
    ): PaymentResource {
        $before = $this->statusSnapshot($payment);

        try {
            $payment = $paymentStatusService->markRefunded($payment, $request->validated('reason'));
        } catch (PaymentOperationException $exception) {
            throw ValidationException::withMessages(['payment' => [$exception->getMessage()]]);
        }

        $this->recordStatusAudit($auditLogService, $request, $payment, 'admin.payment.refunded', $before);

        return new PaymentResource($payment);
    }

    public function chargeback(
        UpdatePaymentStatusRequest $request,
        Payment $payment,
        PaymentStatusService $paymentStatusService,
        AuditLogService $auditLogService,
    ): PaymentResource {
        $before = $this->statusSnapshot($payment);

        try {
            $payment = $paymentStatusService->markChargeback($payment, $request->validated('reason'));
        } catch (PaymentOperationException $exception) {
            throw ValidationException::withMessages(['payment' => [$exception->getMessage()]]);
        }

        $this->recordStatusAudit($auditLogService, $request, $payment, 'admin.payment.chargeback', $before);

        return new PaymentResource($payment);
    }

    /**
     * @return array<string, mixed>
     */
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
