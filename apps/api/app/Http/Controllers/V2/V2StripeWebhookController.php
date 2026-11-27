<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Services\V2StripeWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class V2StripeWebhookController
{
    public function __invoke(Request $request, V2StripeWorker $worker): JsonResponse
    {
        $body = $request->getContent();
        $secret = config('v2_stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') return $this->response(503);
        if (strlen($body) > 524288) return $this->response(400);
        try {
            $event = Webhook::constructEvent($body, (string) $request->header('Stripe-Signature'), $secret, 300);
            $worker->receive($event->toArray());
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return $this->response(400);
        } catch (\Throwable) {
            return $this->response(503);
        }
        return $this->response(200);
    }

    private function response(int $status): JsonResponse
    {
        return response()->json(['accepted' => $status === 200], $status, ['Cache-Control' => 'no-store']);
    }
}
