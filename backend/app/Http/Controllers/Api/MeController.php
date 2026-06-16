<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
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

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $validated,
        );

        return new UserResource($user->refresh()->load(['wallet', 'profile']));
    }
}
