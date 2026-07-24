<?php

namespace App\Providers;

use App\Auth\V2RealmSessionGuard;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2MfaPolicy;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\Identity\Services\V2RealmBoundary;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class V2AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(V2PasswordPolicy::class);
        $this->app->singleton(V2MfaPolicy::class);
        $this->app->singleton(V2PermissionAuthorizer::class);
        $this->app->singleton(V2RealmBoundary::class);
        $this->app->singleton(V2SessionPolicy::class);
    }

    public function boot(V2PermissionAuthorizer $authorizer): void
    {
        Auth::extend(
            'v2_realm_session',
            static function ($app, string $name, array $config): V2RealmSessionGuard {
                $realm = V2Realm::tryFrom((string) ($config['realm'] ?? ''));
                $provider = Auth::createUserProvider($config['provider'] ?? null);
                if (
                    ! in_array($realm, [V2Realm::User, V2Realm::Admin], true)
                    || $provider === null
                ) {
                    throw new RuntimeException('Invalid V2 Realm Guard configuration.');
                }

                return new V2RealmSessionGuard(
                    $realm,
                    $provider,
                    $app['request'],
                    $app->make(V2SessionPolicy::class)
                );
            }
        );

        Gate::define(
            'v2.permission',
            static fn (Admin $admin, string $permission): bool =>
                $authorizer->allows($admin->role, $permission)
        );
    }
}
