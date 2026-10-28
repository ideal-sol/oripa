<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2UserClosureService;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Http\Responses\V2ProblemDetails;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2AccountClosureController
{
    public function __construct(private readonly V2UserClosureService $closure) {}

    public function preview(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $user = $request->user('v2_user');
            $this->closure->assertEligible($user);

            return $this->closure->preview($user);
        });
    }

    public function emailCode(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            if ($request->getContent() !== '' && $request->json()->all() !== []) throw new V2AuthenticationException('VALIDATION_ERROR', 422);
            return DB::transaction(function () use ($request): array {
                $user = User::query()->whereKey($request->user('v2_user')->id)->lockForUpdate()->firstOrFail();
                $this->closure->assertEligible($user);
                $this->requireSession($request, $user);
                if ($user->password_login_enabled) throw new V2AuthenticationException('ACCOUNT_CLOSURE_VERIFICATION_METHOD_INVALID', 409);
                $now = CarbonImmutable::now()->startOfSecond();
                $recent = DB::table('account_closure_codes')->where('user_id', $user->id)
                    ->where('sent_at', '>', V2DatabaseTimestamp::format($now->subHour()))->orderByDesc('id')->get();
                if ($recent->count() >= 5 || ($recent->first() !== null && CarbonImmutable::parse($recent->first()->sent_at)->addSeconds(60)->greaterThan($now))) {
                    throw new V2AuthenticationException('RATE_LIMITED', 429);
                }
                $at = V2DatabaseTimestamp::format($now);
                DB::table('account_closure_codes')->where('user_id', $user->id)->whereNull('invalidated_at')->update(['invalidated_at' => $at]);
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('account_closure_codes')->insert([
                    'user_id' => $user->id, 'code_hash' => $this->codeHash((int) $user->id, $code),
                    'sent_at' => $at, 'expires_at' => V2DatabaseTimestamp::format($now->addMinutes(10)),
                ]);
                DB::afterCommit(static function () use ($user, $code): void {
                    app(V2TemplateMailDeliveryService::class)->sendSecurity('account_closure_code', $user->email_display, ['closure_code' => $code, 'expires_in_minutes' => '10']);
                });

                return ['masked_email' => $this->closure->maskedEmail($user), 'expires_at' => $now->addMinutes(10)->toIso8601String(), 'resend_available_at' => $now->addSeconds(60)->toIso8601String()];
            }, 3);
        });
    }

    public function close(Request $request): JsonResponse
    {
        $response = $this->respond($request, function () use ($request): array {
            $input = $request->json()->all();
            $key = $request->header('Idempotency-Key');
            if (! is_string($key) || $key === '' || strlen($key) > 255
                || array_diff(array_keys($input), ['confirmed', 'expected_point_balance', 'expected_unclaimed_prize_count', 'password', 'email_code']) !== []
                || ($input['confirmed'] ?? null) !== true
                || ! is_int($input['expected_point_balance'] ?? null) || $input['expected_point_balance'] < 0
                || ! is_int($input['expected_unclaimed_prize_count'] ?? null) || $input['expected_unclaimed_prize_count'] < 0) {
                throw new V2AuthenticationException('VALIDATION_ERROR', 422);
            }
            if ($request->user('v2_user')->password_login_enabled) {
                app(V2RateLimiter::class)->assertSubject('password_change', $request->user('v2_user')->public_id);
            }
            $result = app(V2PointTransactionRunner::class)->run(function () use ($request, $input, $key): array {
                $user = User::query()->whereKey($request->user('v2_user')->id)->lockForUpdate()->firstOrFail();
                $this->closure->assertEligible($user);
                $this->requireSession($request, $user);
                $code = null;
                if ($user->password_login_enabled) {
                    if (array_key_exists('email_code', $input) || ! is_string($input['password'] ?? null) || mb_strlen($input['password']) < 8 || mb_strlen($input['password']) > 128
                        || ! app(V2PasswordPolicy::class)->verify($input['password'], $user->password_hash)) {
                        return ['error' => 'INVALID_PASSWORD'];
                    }
                } else {
                    $code = DB::table('account_closure_codes')->where('user_id', $user->id)->whereNull('invalidated_at')->orderByDesc('id')->lockForUpdate()->first();
                    if (array_key_exists('password', $input) || ! is_string($input['email_code'] ?? null) || preg_match('/\A[0-9]{6}\z/', $input['email_code']) !== 1
                        || $code === null || CarbonImmutable::parse($code->expires_at)->lessThanOrEqualTo(now())
                        || ! hash_equals($code->code_hash, $this->codeHash((int) $user->id, $input['email_code']))) {
                        if ($code !== null) DB::table('account_closure_codes')->where('id', $code->id)->update([
                            'failed_attempts' => min(5, (int) $code->failed_attempts + 1),
                            'invalidated_at' => (int) $code->failed_attempts >= 4 ? V2DatabaseTimestamp::format(now()->startOfSecond()) : null,
                        ]);

                        return ['error' => 'INVALID_EMAIL_CODE'];
                    }
                }
                $idempotency = app(V2PointIdempotencyService::class);
                $claim = $idempotency->claim('user.account_closure', 'user', $user->public_id, $key, [
                    'confirmed' => true, 'expected_point_balance' => $input['expected_point_balance'],
                    'expected_unclaimed_prize_count' => $input['expected_unclaimed_prize_count'],
                ]);
                if ($claim->replay) return $claim->record->response_data;
                $preview = $this->closure->preview($user);
                if ($preview['point_balance'] !== $input['expected_point_balance'] || $preview['unclaimed_prize_count'] !== $input['expected_unclaimed_prize_count']) {
                    throw new V2AuthenticationException('ACCOUNT_CLOSURE_STALE', 409);
                }
                $data = $this->closure->closeLocked($user, 'self', 'normal', (string) Str::uuid7());
                if ($code !== null) DB::table('account_closure_codes')->where('id', $code->id)->update(['invalidated_at' => V2DatabaseTimestamp::format(now()->startOfSecond())]);
                $idempotency->complete($claim->record, 'user', $user->public_id, $data);

                return $data;
            });
            if (isset($result['error'])) throw new V2AuthenticationException($result['error'], 422);

            return $result;
        });
        if ($response->isSuccessful()) {
            app(V2SessionManager::class)->expireSession($response, \App\Domain\Identity\Enums\V2Realm::User);
            app(\App\Domain\Identity\Services\V2CsrfService::class)->expire($response, \App\Domain\Identity\Enums\V2Realm::User);
        }

        return $response;
    }

    private function respond(Request $request, callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()], 200, ['Cache-Control' => 'private, no-store']);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (\App\Domain\Point\Exceptions\V2PointException $exception) {
            if (! in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)) throw $exception;
            return V2ProblemDetails::fromAuthentication($request, new V2AuthenticationException($exception->getMessage(), 409));
        }
    }

    private function requireSession(Request $request, User $user): void
    {
        try {
            app(V2SessionManager::class)->requireActiveUserSession($request, (int) $user->id, true);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'A current User Session is required.') throw $error;
            throw new V2AuthenticationException('SESSION_EXPIRED', 401);
        }
    }

    private function codeHash(int $userId, #[\SensitiveParameter] string $code): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') throw new \RuntimeException('Closure code key unavailable.');

        return hash_hmac('sha256', 'account-closure:'.$userId.':'.$code, $key);
    }
}
