<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminAuthenticationService;
use App\Domain\Identity\Services\V2CsrfService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Http\Controllers\Controller;
use App\Models\V2\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;

final class V2AdminAuthController extends Controller
{
    private const TRANSACTION_COOKIE = '__Host-oripa_admin_auth_transaction';

    public function __construct(
        private readonly V2AdminAuthenticationService $authentication,
        private readonly V2SessionManager $sessions,
        private readonly V2CsrfService $csrf
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'password' => ['required', 'string'],
            'invitation_token' => ['sometimes', 'string', 'size:64'],
        ]);
        $result = $this->authentication->login(
            $data['email'],
            $data['password'],
            $request->ip() ?? 'unknown',
            $data['invitation_token'] ?? null
        );
        $response = response()->json([
            'status' => 'mfa_required',
            'transaction_token' => $result['transaction_token'],
            'expires_in' => $result['expires_in'],
            'methods' => $result['methods'],
            'webauthn' => $result['webauthn'],
        ], 202);
        $this->attachTransactionCookie(
            $response,
            $result['transaction_token'],
            $result['expires_in']
        );
        $this->csrf->rotate($response, V2Realm::Admin);

        return $response;
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'method' => ['required', 'in:totp,webauthn,recovery_code'],
            'code' => ['sometimes', 'string', 'max:128'],
            'challenge_token' => ['sometimes', 'string', 'size:64'],
            'credential' => ['sometimes', 'array'],
        ]);
        $transaction = $this->transactionToken($request);
        $result = $this->authentication->verifyMfa(
            $transaction,
            $data['method'],
            $data['code'] ?? null,
            $data['challenge_token'] ?? null,
            $data['credential'] ?? []
        );
        $response = response()->json([
            'authenticated' => ! $result['requires_mfa_enrollment'],
            'requires_mfa_enrollment' => $result['requires_mfa_enrollment'],
            'enrollment_transaction_token' =>
                $result['enrollment_transaction']['token'] ?? null,
            'enrollment_transaction_expires_in' =>
                $result['enrollment_transaction']['expires_in'] ?? null,
            'admin' => [
                'id' => $result['admin']->public_id,
                'role' => $result['admin']->role->value,
                'state' => $result['admin']->state->value,
            ],
        ]);
        $this->sessions->attachSession(
            $response,
            V2Realm::Admin,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        if ($result['enrollment_transaction'] === null) {
            $this->expireTransactionCookie($response);
        } else {
            $this->attachTransactionCookie(
                $response,
                $result['enrollment_transaction']['token'],
                $result['enrollment_transaction']['expires_in']
            );
        }
        $this->csrf->rotate($response, V2Realm::Admin);

        return $response;
    }

    public function beginTotp(Request $request): JsonResponse
    {
        return response()->json($this->authentication->beginTotp(
            $this->transactionToken($request)
        ), 201);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'enrollment_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        $this->authentication->confirmTotp(
            $this->transactionToken($request),
            $data['enrollment_token'],
            $data['code']
        );

        return response()->json(['status' => 'confirmed']);
    }

    public function webauthnOptions(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'label' => ['sometimes', 'string', 'min:1', 'max:100'],
        ]);

        return response()->json($this->authentication->beginWebauthn(
            $this->transactionToken($request),
            $data['label'] ?? 'Authenticator'
        ), 201);
    }

    public function storeWebauthn(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'challenge_token' => ['required', 'string', 'size:64'],
            'credential' => ['required', 'array'],
        ]);
        $this->authentication->completeWebauthn(
            $this->transactionToken($request),
            $data['challenge_token'],
            $data['credential']
        );

        return response()->json(['status' => 'registered'], 201);
    }

    public function regenerateRecoveryCodes(): JsonResponse
    {
        $admin = Auth::guard('v2_admin')->user();
        if (! $admin instanceof Admin) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Admin authentication is required.'
            );
        }

        return response()->json([
            'recovery_codes' => $this->authentication->regenerateRecoveryCodes($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logout($request);
        $response = response()->json(null, 204);
        $this->sessions->expireSession($response, V2Realm::Admin);
        $this->csrf->rotate($response, V2Realm::Admin);

        return $response;
    }

    public function session(): JsonResponse
    {
        $admin = Auth::guard('v2_admin')->user();
        $response = response()->json([
            'authenticated' => $admin !== null,
            'admin' => $admin === null ? null : [
                'id' => $admin->public_id,
                'role' => $admin->role->value,
                'state' => $admin->state->value,
                'mfa_verified' => true,
            ],
        ]);
        $this->csrf->rotate($response, V2Realm::Admin);

        return $response;
    }

    private function transactionToken(Request $request): string
    {
        $header = $request->header('X-Oripa-Auth-Transaction');
        $cookie = $request->cookie(self::TRANSACTION_COOKIE);
        if (
            ! is_string($header)
            || ! is_string($cookie)
            || ! hash_equals($cookie, $header)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $header)
        ) {
            throw new V2AuthenticationException(
                'INVALID_AUTH_TRANSACTION',
                401,
                'The authentication transaction is invalid or expired.'
            );
        }

        return $header;
    }

    private function attachTransactionCookie(
        JsonResponse $response,
        #[SensitiveParameter] string $token,
        int $expiresIn
    ): void {
        $response->headers->setCookie(new Cookie(
            self::TRANSACTION_COOKIE,
            $token,
            now()->addSeconds($expiresIn),
            '/',
            null,
            true,
            true,
            false,
            'strict'
        ));
    }

    private function expireTransactionCookie(JsonResponse $response): void
    {
        $response->headers->setCookie(new Cookie(
            self::TRANSACTION_COOKIE,
            '',
            1,
            '/',
            null,
            true,
            true,
            false,
            'strict'
        ));
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function validate(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new V2AuthenticationException(
                'INVALID_REQUEST',
                422,
                'The authentication request is invalid.'
            );
        }

        return $validator->validated();
    }
}
