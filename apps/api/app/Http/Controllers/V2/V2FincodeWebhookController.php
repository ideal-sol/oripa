<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class V2FincodeWebhookController
{
    public function __construct(private readonly V2FincodeWebhookService $webhooks)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->webhooks->process($request->getContent(), $request->header('Fincode-Signature'));

            return response()->json(['receive' => '0']);
        } catch (V2FincodeException $exception) {
            return response()->json([
                'type' => 'https://oripa.example/problems/'.strtolower($exception->errorCode),
                'title' => $exception->getMessage(),
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'retryable' => $exception->retryable,
            ], $exception->status, ['Content-Type' => 'application/problem+json']);
        }
    }
}
