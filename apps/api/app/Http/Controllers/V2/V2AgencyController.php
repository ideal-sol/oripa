<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AgencyPortalService;
use App\Domain\Identity\Services\V2CsrfService;
use App\Domain\Identity\Services\V2SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class V2AgencyController
{
    public function __construct(
        private readonly V2AgencyPortalService $portal,
        private readonly V2SessionManager $sessions,
        private readonly V2CsrfService $csrf
    ) {
    }

    public function session(Request $request): JsonResponse
    {
        $data = null;
        try {
            $data = $this->portal->current($request)['data'];
        } catch (V2AuthenticationException $exception) {
            if ($exception->status !== 401) {
                throw $exception;
            }
        }
        $response = $this->response(['authenticated' => $data !== null, 'agency' => $data]);
        $this->csrf->attachIfMissing($request, $response, V2Realm::Agency);

        return $response;
    }

    public function login(Request $request): JsonResponse
    {
        return $this->withSession($this->portal->login($request, $request->all()));
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->response($this->portal->current($request));
    }

    public function contact(Request $request): JsonResponse
    {
        return $this->withSession($this->portal->current($request, 'contact', $request->all()));
    }

    public function email(Request $request): JsonResponse
    {
        return $this->withSession($this->portal->current($request, 'email', $request->all()));
    }

    public function password(Request $request): JsonResponse
    {
        return $this->withSession($this->portal->current($request, 'password', $request->all()));
    }

    public function logout(Request $request): JsonResponse
    {
        $response = $this->response($this->portal->current($request, 'logout', $request->all()));
        $this->sessions->expireSession($response, V2Realm::Agency);
        $this->csrf->expire($response, V2Realm::Agency);

        return $response;
    }

    private function withSession(array $result): JsonResponse
    {
        $session = $result['session'] ?? null;
        unset($result['session']);
        $response = $this->response($result);
        if ($session !== null) {
            $this->sessions->attachSession($response, V2Realm::Agency, $session['token'], $session['absolute_expires_at']);
            $this->csrf->rotate($response, V2Realm::Agency);
        }

        return $response;
    }

    private function response(array $body): JsonResponse
    {
        return response()->json($body, 200, [
            'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie',
            'X-Oripa-Api-Version' => '2', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
