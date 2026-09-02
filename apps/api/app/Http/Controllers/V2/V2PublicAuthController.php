<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2CsrfService;
use App\Domain\Identity\Services\V2EmailChangeService;
use App\Domain\Identity\Services\V2ExternalIdentityService;
use App\Domain\Identity\Services\V2PasswordChangeService;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Http\Responses\V2ProblemDetails;
use App\Models\V2\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class V2PublicAuthController
{
    public function __construct(
        private readonly V2UserAuthenticationService $authentication,
        private readonly V2PasswordRecoveryService $passwordRecovery,
        private readonly V2EmailChangeService $emailChanges,
        private readonly V2PasswordChangeService $passwordChanges,
        private readonly V2SmsVerificationService $smsVerification,
        private readonly V2ExternalIdentityService $externalIdentities,
        private readonly V2SessionManager $sessions,
        private readonly V2CsrfService $csrf
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'password' => ['required', 'string'],
            'redirect_path' => ['sometimes', 'string', 'max:2048'],
        ]);
        $user = $this->authentication->register(
            $data['email'],
            $data['password'],
            $data['redirect_path'] ?? '/',
            $request->ip() ?? 'unknown'
        );

        return $this->privateResponse(response()->json([
            'status' => 'pending_verification',
            'user_id' => $user->public_id,
        ], 202));
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'user_id' => ['required', 'uuid'],
            'redirect_path' => ['sometimes', 'string', 'max:2048'],
        ]);
        $this->authentication->resend($data['user_id'], $data['redirect_path'] ?? '/');

        return $this->privateResponse(response()->json(['status' => 'accepted'], 202));
    }

    public function verify(
        Request $request,
        string $userId,
        string $hash
    ): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->authentication->verify($userId, $hash);
        } catch (V2AuthenticationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $response = new RedirectResponse(
                '/verify-email/error',
                Response::HTTP_SEE_OTHER
            );
            $this->applyPrivateHeaders($response);
            $response->setVary('Accept');

            return $response;
        }
        if ($request->expectsJson()) {
            $response = $this->privateResponse(response()->json([
                'authenticated' => true,
                'user' => [
                    'id' => $result['user']->public_id,
                    'state' => $result['user']->state->value,
                    'email_verified' => true,
                ],
                'redirect_path' => $result['redirect_path'],
            ]));
        } else {
            $response = new RedirectResponse(
                $result['redirect_path'],
                Response::HTTP_SEE_OTHER
            );
            $this->applyPrivateHeaders($response);
        }
        $response->setVary('Accept');
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'password' => ['required', 'string'],
        ]);
        $result = $this->authentication->login(
            $data['email'],
            $data['password'],
            $request->ip() ?? 'unknown'
        );
        $this->sessions->revoke($request, V2Realm::User);
        $response = $this->privateResponse(response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $result['user']->public_id,
                'state' => $result['user']->state->value,
                'email_verified' => true,
            ],
        ]));
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function startGoogleLogin(Request $request): JsonResponse
    {
        return $this->startExternalLogin($request, 'google');
    }

    public function startLineLogin(Request $request): JsonResponse
    {
        return $this->startExternalLogin($request, 'line');
    }

    private function startExternalLogin(Request $request, string $provider): JsonResponse
    {
        $data = $this->validate($request, [
            'return_path' => ['sometimes', 'string', 'max:255'],
        ]);
        $result = $this->externalIdentities->startForProvider(
            $provider,
            'login',
            $data['return_path'] ?? '/',
            $request->ip() ?? 'unknown',
            (string) $request->header('X-Request-ID'),
            null,
            $request
        );
        $response = response()->json([
            'provider' => $provider,
            'authorization_url' => $result['authorization_url'],
            'expires_at' => $result['expires_at']->format(DATE_ATOM),
        ]);
        $this->attachExternalTransactionCookie(
            $response,
            $result['binding_token'],
            $result['expires_at']
        );

        return $response;
    }

    public function completeGoogle(Request $request): JsonResponse
    {
        return $this->completeExternal($request, 'google');
    }

    public function completeLine(Request $request): JsonResponse
    {
        return $this->completeExternal($request, 'line');
    }

    private function completeExternal(Request $request, string $provider): JsonResponse
    {
        $data = $this->validate($request, [
            'code' => ['required', 'string', 'max:4096'],
            'state' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'iss' => ['sometimes', 'string', 'max:255'],
        ]);
        if (
            isset($data['iss'])
            && (
                $provider !== 'google'
                || $data['iss'] !== 'https://accounts.google.com'
            )
        ) {
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                401,
                'The external identity request could not be completed.'
            );
        }
        $cookieName = (string) config(
            'v2_identity.external_identity.transaction_cookie',
            '__Host-oripa_oidc_transaction'
        );
        $binding = $request->cookies->get($cookieName);
        if (! is_string($binding) || ! preg_match('/\A[0-9a-f]{64}\z/', $binding)) {
            throw new V2AuthenticationException(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                401,
                'The external identity request could not be completed.'
            );
        }
        $result = $this->externalIdentities->callbackForProvider(
            $provider,
            $data['state'],
            $data['code'],
            $binding,
            $request->url(),
            $request->ip() ?? 'unknown',
            $request
        );
        $response = response()->json([
            'authenticated' => true,
            'purpose' => $result['purpose'],
            'provider' => $provider,
            'return_path' => $result['return_path'],
            'user' => [
                'id' => $result['user']->public_id,
                'state' => $result['user']->state->value,
                'email_verified' => true,
            ],
        ]);
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);
        $this->expireExternalTransactionCookie($response);

        return $response;
    }

    public function startGoogleLink(Request $request): JsonResponse
    {
        return $this->startAuthenticatedExternalTransaction($request, 'google', 'link');
    }

    public function startGoogleReauthentication(Request $request): JsonResponse
    {
        return $this->startAuthenticatedExternalTransaction(
            $request,
            'google',
            'reauthentication'
        );
    }

    public function startLineLink(Request $request): JsonResponse
    {
        return $this->startAuthenticatedExternalTransaction($request, 'line', 'link');
    }

    public function startLineReauthentication(Request $request): JsonResponse
    {
        return $this->startAuthenticatedExternalTransaction(
            $request,
            'line',
            'reauthentication'
        );
    }

    public function linkedIdentities(): JsonResponse
    {
        return response()->json([
            'items' => $this->externalIdentities->identities($this->currentUser())->values(),
        ]);
    }

    public function reauthenticatePassword(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'password' => ['required', 'string', 'max:128'],
        ]);
        $session = $this->externalIdentities->reauthenticatePassword(
            $this->currentUser(),
            $request,
            $data['password']
        );
        $response = response()->json([
            'reauthenticated' => true,
            'method' => 'password',
        ]);
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $session['token'],
            $session['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function unlinkGoogle(Request $request): JsonResponse
    {
        return $this->unlinkExternal($request, 'google');
    }

    public function unlinkLine(Request $request): JsonResponse
    {
        return $this->unlinkExternal($request, 'line');
    }

    private function unlinkExternal(Request $request, string $provider): JsonResponse
    {
        $session = $this->externalIdentities->unlink(
            $provider,
            $this->currentUser(),
            $request
        );
        $response = $this->privateResponse(response()->json(null, 204));
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $session['token'],
            $session['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logout($request);
        $response = response()->json(null, 204);
        $this->sessions->expireSession($response, V2Realm::User);
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'redirect_path' => ['sometimes', 'string', 'max:2048'],
        ]);
        $this->passwordRecovery->request(
            $data['email'],
            $data['redirect_path'] ?? '/',
            $request->ip() ?? 'unknown'
        );

        return $this->privateResponse(response()->json([
            'status' => 'accepted',
            'message' => V2PasswordRecoveryService::GENERIC_ACCEPTED,
        ], 202));
    }

    public function confirmPasswordReset(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        $token = $request->input('token');
        if (
            ! is_string($userId)
            || ! Str::isUuid($userId)
            || ! is_string($token)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $token)
        ) {
            throw new V2AuthenticationException(
                'INVALID_PASSWORD_RESET',
                410,
                'The password reset request is invalid or expired.'
            );
        }
        $data = $this->validate($request, [
            'password' => ['required', 'string', 'max:128'],
        ]);
        $current = Auth::guard('v2_user')->user();
        $result = $this->passwordRecovery->confirm(
            $userId,
            $token,
            $data['password']
        );
        $response = $this->privateResponse(response()->json([
            'status' => 'password_updated',
            'authenticated' => false,
            'user' => null,
            'next_action' => 'login',
            'redirect_path' => $result['redirect_path'],
        ]));
        if ($current instanceof User && hash_equals($current->public_id, $userId)) {
            $this->sessions->expireSession($response, V2Realm::User);
            $this->csrf->expire($response, V2Realm::User);
        }

        return $response;
    }

    public function createEmailChangeRequest(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'redirect_path' => ['sometimes', 'string', 'max:255'],
        ]);
        $result = $this->emailChanges->start(
            $this->currentUser(),
            $request,
            $data['email'],
            $data['redirect_path'] ?? '/'
        );

        return $this->privateResponse(response()->json([
            'status' => 'pending_verification',
            'request_id' => $result['request_id'],
            'expires_at' => $result['expires_at']->format(DATE_ATOM),
        ], 202));
    }

    public function completeEmailChange(
        Request $request,
        string $emailChangeRequestId
    ): JsonResponse {
        $token = $request->input('token');
        if (
            ! Str::isUuid($emailChangeRequestId)
            || ! is_string($token)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $token)
        ) {
            throw new V2AuthenticationException(
                'INVALID_EMAIL_CHANGE_REQUEST',
                410,
                'The email change request is invalid or expired.'
            );
        }
        $result = $this->emailChanges->complete(
            $emailChangeRequestId,
            $token,
            $request
        );
        $response = $this->privateResponse(response()->json([
            'status' => 'completed',
            'authenticated' => $result['session'] !== null,
            'session_rotated' => $result['session'] !== null,
            'initiating_session_preserved' => $result['initiating_session_preserved'],
            'next_action' => 'return_to_account',
        ]));
        if ($result['session'] !== null) {
            $this->sessions->attachSession(
                $response,
                V2Realm::User,
                $result['session']['token'],
                $result['session']['absolute_expires_at']
            );
            $this->csrf->rotate($response, V2Realm::User);
        } elseif ($result['request_session_revoked']) {
            $this->sessions->expireSession($response, V2Realm::User);
            $this->csrf->expire($response, V2Realm::User);
        }

        return $response;
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'current_password' => ['required', 'string', 'max:128'],
            'new_password' => ['required', 'string', 'max:128'],
        ]);
        $result = $this->passwordChanges->change(
            $this->currentUser(),
            $request,
            $data['current_password'],
            $data['new_password']
        );
        $response = $this->privateResponse(response()->json([
            'status' => 'password_updated',
            'authenticated' => true,
            'session_rotated' => true,
            'next_action' => 'return_to_account',
        ]));
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function smsStatus(): JsonResponse
    {
        return response()->json($this->smsVerification->status($this->currentUser()));
    }

    public function sendSms(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'phone' => ['required', 'string', 'max:32'],
        ]);

        return response()->json(
            $this->smsVerification->send(
                $this->currentUser(),
                $request,
                $data['phone']
            ),
            202
        );
    }

    public function resendSms(Request $request): JsonResponse
    {
        return response()->json(
            $this->smsVerification->resend(
                $this->currentUser(),
                $request
            ),
            202
        );
    }

    public function verifySms(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);
        $result = $this->smsVerification->verify(
            $this->currentUser(),
            $request,
            $data['challenge_id'],
            $data['code']
        );
        $response = response()->json($result['status']);
        $this->sessions->attachSession(
            $response,
            V2Realm::User,
            $result['session']['token'],
            $result['session']['absolute_expires_at']
        );
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    public function session(Request $request): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        if (
            $user === null
            && $this->sessions->rawToken($request, V2Realm::User) !== null
        ) {
            $response = V2ProblemDetails::fromAuthentication(
                $request,
                new V2AuthenticationException(
                    'SESSION_EXPIRED',
                    401,
                    'The user session has expired.'
                )
            );
            $this->sessions->expireSession($response, V2Realm::User);
            $this->csrf->rotate($response, V2Realm::User);

            return $response;
        }

        $response = $this->privateResponse(response()->json([
            'authenticated' => $user !== null,
            'user' => $user === null ? null : [
                'id' => $user->public_id,
                'state' => $user->state->value,
                'email_verified' => $user->email_verified_at !== null,
            ],
        ]));
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        $this->applyPrivateHeaders($response);

        return $response;
    }

    private function applyPrivateHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Oripa-Api-Version', '2');
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

    private function currentUser(): User
    {
        $user = Auth::guard('v2_user')->user();
        if (! $user instanceof User) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'Authentication is required.'
            );
        }

        return $user;
    }

    private function startAuthenticatedExternalTransaction(
        Request $request,
        string $provider,
        string $purpose
    ): JsonResponse {
        $data = $this->validate($request, [
            'return_path' => ['sometimes', 'string', 'max:255'],
        ]);
        $user = $this->currentUser();
        $result = $this->externalIdentities->startForProvider(
            $provider,
            $purpose,
            $data['return_path'] ?? '/',
            $request->ip() ?? 'unknown',
            (string) $request->header('X-Request-ID'),
            $user,
            $request
        );
        $response = response()->json([
            'provider' => $provider,
            'purpose' => $purpose,
            'authorization_url' => $result['authorization_url'],
            'expires_at' => $result['expires_at']->format(DATE_ATOM),
        ]);
        $this->attachExternalTransactionCookie(
            $response,
            $result['binding_token'],
            $result['expires_at']
        );

        return $response;
    }

    private function attachExternalTransactionCookie(
        JsonResponse $response,
        string $bindingToken,
        \DateTimeInterface $expiresAt
    ): void {
        $response->headers->setCookie(new Cookie(
            (string) config(
                'v2_identity.external_identity.transaction_cookie',
                '__Host-oripa_oidc_transaction'
            ),
            $bindingToken,
            $expiresAt,
            '/',
            null,
            true,
            true,
            false,
            'lax'
        ));
    }

    private function expireExternalTransactionCookie(JsonResponse $response): void
    {
        $response->headers->setCookie(new Cookie(
            (string) config(
                'v2_identity.external_identity.transaction_cookie',
                '__Host-oripa_oidc_transaction'
            ),
            '',
            1,
            '/',
            null,
            true,
            true,
            false,
            'lax'
        ));
    }
}
