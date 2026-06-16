<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Services\AuditLogService;
use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordAdminAuditLog
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $admin = $request->user();

        if (
            $admin instanceof AdminUser
            && $response->getStatusCode() < 400
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            $this->auditLogService->record(
                action: (string) ($request->route()?->getName() ?? 'admin.api.request'),
                adminUser: $admin,
                request: $request,
                metadata: [
                    'method' => $request->method(),
                    'path' => $request->path(),
                ],
            );
        }

        return $response;
    }
}
