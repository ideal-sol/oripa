<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['wallet', 'profile']));
    }

    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $validated = $request->validated();
        $user = $request->user();

        $user->forceFill([
            'name' => $validated['name'],
        ])->save();

        unset($validated['name']);

        $currentNormalizedPhoneNumber = $user->profile?->normalized_phone_number;
        $nextNormalizedPhoneNumber = array_key_exists('phone_number', $validated)
            ? UserProfile::normalizePhoneNumber($validated['phone_number'])
            : $currentNormalizedPhoneNumber;

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $validated,
        );

        if ($currentNormalizedPhoneNumber !== $nextNormalizedPhoneNumber) {
            $user->forceFill(['sms_verified_at' => null])->save();
        }

        return new UserResource($user->refresh()->load(['wallet', 'profile']));
    }
}
