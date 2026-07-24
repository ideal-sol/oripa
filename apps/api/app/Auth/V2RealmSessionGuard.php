<?php

namespace App\Auth;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class V2RealmSessionGuard implements Guard
{
    private ?Authenticatable $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly V2Realm $realm,
        private readonly UserProvider $provider,
        private Request $request,
        private readonly V2SessionPolicy $sessionPolicy
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $policy = $this->sessionPolicy->forRealm($this->realm);
        $rawSessionId = $this->request->cookies->get($policy['cookie']);
        if (! is_string($rawSessionId) || ! preg_match('/\A[0-9a-f]{64}\z/', $rawSessionId)) {
            return null;
        }

        $identityColumn = $this->realm === V2Realm::User ? 'user_id' : 'admin_id';
        $query = DB::table($policy['table'])
            ->where('session_id_hash', $this->sessionPolicy->hashSessionId($rawSessionId))
            ->whereNull('revoked_at')
            ->where('idle_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now());

        if ($this->realm === V2Realm::Admin) {
            $query->whereNotNull('mfa_verified_at');
        }

        $identityId = $query->value($identityColumn);
        if (! is_int($identityId) && ! is_string($identityId)) {
            return null;
        }

        $this->user = $this->provider->retrieveById($identityId);

        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->user = null;
        $this->resolved = false;
    }
}
