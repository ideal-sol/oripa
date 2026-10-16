<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2PreRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class V2PreRegistrationController
{
    public function __construct(private readonly V2PreRegistrationService $service, private readonly V2AdminFreshMfaAuthorizer $authorization)
    {
    }

    public function campaign(): JsonResponse
    {
        return $this->response(['data' => $this->service->campaign()]);
    }

    public function status(): JsonResponse
    {
        return $this->response(['data' => $this->service->status(Auth::guard('v2_user')->user())]);
    }

    public function adminShow(Request $request): JsonResponse
    {
        return $this->response(['data' => $this->service->read($this->authorization->context($request))]);
    }

    public function adminUpdate(Request $request): JsonResponse
    {
        return $this->response($this->service->update($this->authorization->context($request), (string) $request->header('Idempotency-Key'), $request->json()->all()));
    }

    public function adminPreview(Request $request): JsonResponse
    {
        return $this->response($this->service->preview($this->authorization->context($request), $request->json()->all()));
    }

    public function adminActivate(Request $request): JsonResponse
    {
        return $this->response($this->service->activate($this->authorization->context($request), (string) $request->header('Idempotency-Key'), $request->json()->all()));
    }

    public function adminUser(Request $request, string $userId): JsonResponse
    {
        return $this->response(['data' => $this->service->userDetail($this->authorization->context($request), $userId)]);
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data, 200, [
            'Cache-Control' => 'private, no-store', 'X-Oripa-Api-Version' => '2',
            ...(isset($data['idempotent_replay']) ? ['Idempotency-Replayed' => $data['idempotent_replay'] ? 'true' : 'false'] : []),
        ]);
    }
}
