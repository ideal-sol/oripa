<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetMail;
use App\Mail\UserRegisteredMail;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'status' => 'active',
            ]);

            UserProfile::query()->create([
                'user_id' => $user->id,
                'last_name' => $validated['last_name'] ?? null,
                'first_name' => $validated['first_name'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
            ]);

            Wallet::query()->create([
                'user_id' => $user->id,
                'paid_balance' => 0,
                'free_balance' => 0,
            ]);

            return $user;
        });

        Mail::to($user->email, $user->name)->send(new UserRegisteredMail($user));

        $token = $user->createToken($request->deviceName(), ['user'])->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => new UserResource($user->load(['wallet', 'profile'])),
        ], 201);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $user = User::query()
            ->where('email', $email)
            ->where('status', 'active')
            ->first();

        // 登録有無をレスポンスで判別できないよう、存在する有効ユーザーの場合だけメールを送る。
        if ($user) {
            $token = Password::broker()->createToken($user);
            Mail::to($user->email, $user->name)->send(new PasswordResetMail($user, $token));
        }

        return response()->json([
            'message' => 'If the email exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $status = Password::broker()->reset(
            [
                'email' => $payload['email'],
                'password' => $payload['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                ])->save();

                // パスワード変更後は既存ログイントークンを失効させる。
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['The password reset token is invalid or expired.'],
            ]);
        }

        return response()->json([
            'message' => 'Password has been reset.',
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->email())
            ->first();

        if (! $user || $user->status !== 'active' || ! Hash::check($request->password(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $token = $user->createToken($request->deviceName(), ['user'])->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => new UserResource($user->load(['wallet', 'profile'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
