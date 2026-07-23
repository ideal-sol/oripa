<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notification\Services\SmsVerificationService;
use App\Http\Requests\Api\SendSmsVerificationRequest;
use App\Http\Requests\Api\VerifySmsCodeRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SmsVerificationController extends Controller
{
    public function show(Request $request, SmsVerificationService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->status($request->user()),
        ]);
    }

    public function send(SendSmsVerificationRequest $request, SmsVerificationService $service): JsonResponse
    {
        $verification = $service->send($request->user(), $request->phoneNumber());

        return response()->json([
            'message' => 'SMS verification code has been sent.',
            'data' => [
                'id' => $verification->id,
                'phone_number' => $verification->phone_number,
                'expires_at' => $verification->expires_at?->toIso8601String(),
                'max_attempts' => $verification->max_attempts,
            ],
        ], 201);
    }

    public function resend(SendSmsVerificationRequest $request, SmsVerificationService $service): JsonResponse
    {
        $verification = $service->send($request->user(), $request->phoneNumber());

        return response()->json([
            'message' => 'SMS verification code has been resent.',
            'data' => [
                'id' => $verification->id,
                'phone_number' => $verification->phone_number,
                'expires_at' => $verification->expires_at?->toIso8601String(),
                'max_attempts' => $verification->max_attempts,
            ],
        ]);
    }

    public function verify(VerifySmsCodeRequest $request, SmsVerificationService $service): JsonResponse
    {
        $user = $service->verify($request->user(), $request->code());

        return response()->json([
            'message' => 'SMS verification has been completed.',
            'user' => new UserResource($user),
        ]);
    }
}
