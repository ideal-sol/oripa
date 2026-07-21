<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Services\ChargebackPrizeActionService;
use App\Domain\Payment\Services\PaymentReturnRequestMailService;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Http\Requests\Admin\IndexPaymentReversalRequest;
use App\Http\Requests\Admin\UpdatePaymentReversalActionRequest;
use App\Http\Resources\PaymentReversalPrizeActionResource;
use App\Http\Resources\PaymentReversalResource;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use RuntimeException;

class AdminPaymentReversalController extends Controller
{
    public function index(IndexPaymentReversalRequest $request): AnonymousResourceCollection
    {
        $query = PaymentReversal::query()
            ->with('payment.user', 'user', 'adminUser')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', (int) $request->input('payment_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $from = Carbon::createFromFormat('Y-m-d', $request->string('date_from')->toString(), 'Asia/Tokyo')
                ->startOfDay();
            $query->where('occurred_at', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::createFromFormat('Y-m-d', $request->string('date_to')->toString(), 'Asia/Tokyo')
                ->addDay()
                ->startOfDay();
            $query->where('occurred_at', '<', $to);
        }

        return PaymentReversalResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(PaymentReversal $paymentReversal): PaymentReversalResource
    {
        return new PaymentReversalResource($paymentReversal->load(
            'payment.user',
            'user',
            'adminUser',
            'pointEntries.pointLot',
            'pointEntries.pointLedger',
            'prizeActions.userPrize.prize.rank',
            'prizeActions.shippingItem',
        ));
    }

    public function releaseHolds(
        UpdatePaymentReversalActionRequest $request,
        PaymentReversal $paymentReversal,
        ChargebackPrizeActionService $chargebackPrizeActionService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        $summary = $chargebackPrizeActionService->releaseHolds($paymentReversal, $request->validated('note'));
        $paymentReversal = $paymentReversal->refresh()->load('payment.user', 'user', 'prizeActions.userPrize', 'prizeActions.shippingItem');

        $auditLogService->record(
            action: 'admin.payment-reversal.release-holds',
            adminUser: $request->user(),
            auditable: $paymentReversal,
            request: $request,
            metadata: $summary,
        );

        return (new PaymentReversalResource($paymentReversal))
            ->response()
            ->setStatusCode(200);
    }

    public function markReturned(
        UpdatePaymentReversalActionRequest $request,
        PaymentReversalPrizeAction $action,
        ChargebackPrizeActionService $chargebackPrizeActionService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        try {
            $action = $chargebackPrizeActionService->markReturned($action, $request->validated('note'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        $auditLogService->record(
            action: 'admin.payment-reversal-prize-action.mark-returned',
            adminUser: $request->user(),
            auditable: $action,
            request: $request,
        );

        return (new PaymentReversalPrizeActionResource($action))
            ->response()
            ->setStatusCode(200);
    }

    public function sendReturnRequestMail(
        Request $request,
        PaymentReversal $paymentReversal,
        PaymentReturnRequestMailService $paymentReturnRequestMailService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        $returnActionCount = $paymentReversal->prizeActions()
            ->where('action_type', PaymentReversalPrizeActionType::ReturnRequested->value)
            ->count();

        if ($returnActionCount === 0) {
            throw ValidationException::withMessages([
                'payment_reversal' => ['返送依頼対象の景品がありません。'],
            ]);
        }

        $summary = $paymentReturnRequestMailService->sendForReversal($paymentReversal);
        $paymentReversal = $paymentReversal->refresh()->load(
            'payment.user',
            'user',
            'prizeActions.userPrize.prize',
            'prizeActions.shippingItem',
        );

        $auditLogService->record(
            action: 'admin.payment-reversal.send-return-request-mail',
            adminUser: $request->user(),
            auditable: $paymentReversal,
            request: $request,
            metadata: $summary,
        );

        return response()->json([
            'data' => $summary,
            'payment_reversal' => [
                'data' => (new PaymentReversalResource($paymentReversal))->resolve($request),
            ],
        ]);
    }
}
