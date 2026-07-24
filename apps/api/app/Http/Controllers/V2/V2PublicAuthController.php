<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2CsrfService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class V2PublicAuthController extends Controller
{
    public function __construct(
        private readonly V2UserAuthenticationService $authentication,
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

        return response()->json([
            'status' => 'pending_verification',
            'user_id' => $user->public_id,
        ], 202);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'user_id' => ['required', 'uuid'],
            'redirect_path' => ['sometimes', 'string', 'max:2048'],
        ]);
        $this->authentication->resend($data['user_id'], $data['redirect_path'] ?? '/');

        return response()->json(['status' => 'accepted'], 202);
    }

    public function verify(Request $request, string $userId, string $hash): JsonResponse
    {
        $result = $this->authentication->verify($userId, $hash);
        $response = response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $result['user']->public_id,
                'state' => $result['user']->state->value,
                'email_verified' => true,
            ],
            'redirect_path' => $result['redirect_path'],
        ]);
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
        $response = response()->json([
            'authenticated' => true,
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

    public function session(): JsonResponse
    {
        $user = Auth::guard('v2_user')->user();
        $response = response()->json([
            'authenticated' => $user !== null,
            'user' => $user === null ? null : [
                'id' => $user->public_id,
                'state' => $user->state->value,
                'email_verified' => $user->email_verified_at !== null,
            ],
        ]);
        $this->csrf->rotate($response, V2Realm::User);

        return $response;
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
