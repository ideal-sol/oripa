<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Line\Services\V2LineBotManagement;
use App\Domain\Point\Exceptions\V2PointException;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class V2AdminLineBotController
{
    public function __construct(private readonly V2LineBotManagement $management, private readonly V2AdminFreshMfaAuthorizer $authorization) {}

    public function __invoke(Request $request, ?string $botId = null, ?string $operation = null): JsonResponse
    {
        $requestId = (string) Str::uuid7();
        $request->attributes->set('v2_request_id', $requestId);
        try {
            $context = $this->authorization->context($request, $requestId);
            $login = $request->routeIs('v2.admin.line-login.*');
            $result = $request->isMethod('get')
                ? $this->management->read($context, $login)
                : $this->management->mutate($context, $login ? 'login' : ($operation ?? ($botId === null ? 'create' : 'update')), $botId, (string) $request->header('Idempotency-Key', ''), $request->json()->all());
            return response()->json($result, 200, ['Cache-Control' => 'private, no-store', 'X-Request-Id' => $requestId]);
        } catch (V2AuthenticationException $exception) {
            return V2ProblemDetails::fromAuthentication($request, $exception);
        } catch (Throwable $exception) {
            $code = 'LINE_CONFIGURATION_UNAVAILABLE';
            $status = 503;
            if ($exception instanceof V2LineMessagingException) {
                $code = $exception->errorCode;
                $status = $exception->status;
            } elseif ($exception instanceof V2PointException) {
                $code = 'IDEMPOTENCY_CONFLICT';
                $status = 409;
            }
            return response()->json(['type' => 'https://oripa.example/problems/'.strtolower($code), 'title' => $code, 'code' => $code, 'status' => $status, 'request_id' => $requestId, 'retryable' => $status === 503], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'private, no-store']);
        }
    }
}
