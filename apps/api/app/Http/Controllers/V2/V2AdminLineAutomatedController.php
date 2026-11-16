<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Line\Services\V2LineAutomatedSettings;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminLineAutomatedController
{
    public function __invoke(Request $request, ?string $event = null): JsonResponse
    {
        $requestId = (string) Str::uuid7();
        $request->attributes->set('v2_request_id', $requestId);
        try {
            $context = app(V2AdminFreshMfaAuthorizer::class)->context($request, $requestId);
            $service = app(V2LineAutomatedSettings::class);
            $data = $request->isMethod('get') ? $service->read($context) : $service->update($context, $event ?? '', $request->json()->all());
            return response()->json($data, 200, ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId]);
        } catch (V2AuthenticationException $e) {
            return V2ProblemDetails::fromAuthentication($request, $e);
        } catch (\Throwable $e) {
            $status = $e instanceof V2LineMessagingException ? $e->status : 503;
            $code = $e instanceof V2LineMessagingException ? $e->errorCode : 'LINE_AUTOMATED_UNAVAILABLE';
            return response()->json(['type' => 'https://oripa.example/problems/'.strtolower($code), 'title' => $code,
                'code' => $code, 'status' => $status, 'request_id' => $requestId, 'retryable' => $status === 503], $status,
                ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'private, no-store']);
        }
    }
}
