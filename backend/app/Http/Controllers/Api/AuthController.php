<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResendEmailVerificationRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetMail;
use App\Mail\UserEmailVerificationMail;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserReferral;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $referrer = ! empty($validated['referral_code'])
                ? User::query()->where('referral_code', $validated['referral_code'])->lockForUpdate()->first()
                : null;

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'referral_code' => User::generateReferralCode(),
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

            if ($referrer && (int) $referrer->id !== (int) $user->id) {
                $setting = ReferralSetting::current();

                UserReferral::query()->create([
                    'referrer_user_id' => $referrer->id,
                    'referred_user_id' => $user->id,
                    'referral_code' => $validated['referral_code'],
                    'status' => 'pending',
                    'reward_point_amount' => $setting->is_active ? (int) $setting->reward_point_amount : 0,
                    'reward_expiration_days' => $setting->is_active ? $setting->reward_expiration_days : null,
                ]);
            }

            return $user;
        });

        $this->sendEmailVerificationMail($user);

        return response()->json([
            'message' => 'Registration has been accepted. Please verify your email address within 24 hours.',
            'user' => new UserResource($user->load(['wallet', 'profile'])),
        ], 201);
    }

    public function verifyEmail(Request $request, User $user, string $hash): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            throw ValidationException::withMessages([
                'email' => ['The email verification link is invalid or expired.'],
            ]);
        }

        if (! hash_equals(sha1($user->email), $hash)) {
            throw ValidationException::withMessages([
                'email' => ['The email verification link is invalid or expired.'],
            ]);
        }

        if (! $user->email_verified_at) {
            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        return response()->json([
            'message' => 'Email has been verified.',
            'user' => new UserResource($user->load(['wallet', 'profile'])),
        ]);
    }

    public function resendEmailVerification(ResendEmailVerificationRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->email())
            ->where('status', 'active')
            ->first();

        if ($user && ! $user->email_verified_at) {
            $this->sendEmailVerificationMail($user);
        }

        return response()->json([
            'message' => 'If the email exists and is not verified, a verification link has been sent.',
        ]);
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

        if (! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before logging in.'],
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

    private function sendEmailVerificationMail(User $user): void
    {
        $apiVerificationUrl = URL::temporarySignedRoute(
            'api.email.verify',
            now()->addHours(24),
            [
                'user' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        Mail::to($user->email, $user->name)->send(new UserEmailVerificationMail($user, $this->frontendEmailVerificationUrl($apiVerificationUrl)));
    }

    private function frontendEmailVerificationUrl(string $apiVerificationUrl): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $token = rtrim(strtr(base64_encode($apiVerificationUrl), '+/', '-_'), '=');

        return "{$frontendUrl}/email/verify?token={$token}";
    }
}
