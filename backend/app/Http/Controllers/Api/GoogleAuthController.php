<?php

namespace App\Http\Controllers\Api;

use App\Domain\Auth\Services\GoogleOAuthService;
use App\Domain\Auth\Services\SocialAuthService;
use App\Http\Requests\Api\CompleteGoogleRegistrationRequest;
use App\Http\Requests\Api\GoogleCallbackRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class GoogleAuthController extends Controller
{
    public function redirect(GoogleOAuthService $googleOAuthService): JsonResponse
    {
        return response()->json($googleOAuthService->authorizationUrl());
    }

    public function callback(
        GoogleCallbackRequest $request,
        GoogleOAuthService $googleOAuthService,
        SocialAuthService $socialAuthService,
    ): JsonResponse {
        $profile = $googleOAuthService->fetchUserProfile($request->code(), $request->state());
        $result = $socialAuthService->handleCallback($profile, $request->deviceName());

        if ($result['status'] === 'authenticated') {
            return response()->json([
                'status' => 'authenticated',
                'token_type' => 'Bearer',
                'access_token' => $result['access_token'],
                'user' => new UserResource($result['user']),
                'next_step' => $result['next_step'],
            ]);
        }

        return response()->json([
            'status' => 'registration_required',
            'registration_token' => $result['registration_token'],
            'profile' => $result['profile'],
            'next_step' => $result['next_step'],
        ]);
    }

    public function register(
        CompleteGoogleRegistrationRequest $request,
        SocialAuthService $socialAuthService,
    ): JsonResponse {
        $result = $socialAuthService->completeRegistration(
            registrationToken: $request->registrationToken(),
            referralCode: $request->referralCode(),
            deviceName: $request->deviceName(),
        );

        return response()->json([
            'status' => 'registered',
            'token_type' => 'Bearer',
            'access_token' => $result['access_token'],
            'user' => new UserResource($result['user']),
            'next_step' => $result['next_step'],
        ], 201);
    }
}
