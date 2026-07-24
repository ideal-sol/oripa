<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Http\Responses\V2ProblemDetails;
use App\Http\Middleware\V2\EnforceV2BrowserSecurity;
use App\Http\Middleware\V2\EnforceV2Realm;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('admin/api')
                ->group(base_path('routes/admin.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn ($request) => null);
        $middleware->alias([
            'v2.browser' => EnforceV2BrowserSecurity::class,
            'v2.realm' => EnforceV2Realm::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e): bool => $request->is('api/*') || $request->is('admin/api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $exception, $request) {
            if ($request->is('api/*') || $request->is('admin/api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        $exceptions->render(function (V2AuthenticationException $exception, $request) {
            if ($request->is('api/v2/*') || $request->is('admin/api/v2/*')) {
                return V2ProblemDetails::fromAuthentication($request, $exception);
            }

            return null;
        });
    })
    ->create();
