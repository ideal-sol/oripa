<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Exceptions\PaymentWebhookException;
use App\Domain\Payment\Services\MockPaymentWebhookSignatureVerifier;
use App\Domain\Payment\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PaymentWebhookController extends Controller
{
    public function handle(
        Request $request,
        MockPaymentWebhookSignatureVerifier $signatureVerifier,
        PaymentWebhookService $service,
    ): JsonResponse {
        $payload = $request->getContent();

        if (! $signatureVerifier->verify($payload, $request->header('X-Mock-Signature'))) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
            ], 400);
        }

        $validator = Validator::make($data, [
            'event_id' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['payment.succeeded', 'payment.failed', 'payment.canceled'])],
            'provider' => ['nullable', Rule::in(['mock'])],
            'provider_payment_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $service->handle($validator->validated());
        } catch (PaymentWebhookException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'received' => true,
            'duplicate' => $result['duplicate'],
            'payment_id' => $result['payment']->id,
            'status' => $result['payment']->status?->value ?? $result['payment']->status,
        ]);
    }
}
