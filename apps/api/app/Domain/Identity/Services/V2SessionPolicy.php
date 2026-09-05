<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2Realm;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;

final class V2SessionPolicy
{
    public function currentTime(): CarbonImmutable
    {
        return $this->canonicalTime(now())->startOfSecond();
    }

    public function canonicalTime(DateTimeInterface|string $instant): CarbonImmutable
    {
        $databaseTimezone = DB::connection()->getConfig('timezone');
        $canonicalTimezone = is_string($databaseTimezone) && $databaseTimezone !== ''
            ? $databaseTimezone
            : (string) config('app.timezone');

        return CarbonImmutable::parse($instant, $canonicalTimezone)->setTimezone($canonicalTimezone);
    }

    /**
     * @return array{table: string, cookie: string, idle_minutes: int, absolute_minutes: int, same_site: string, remember: bool}
     */
    public function forRealm(V2Realm $realm): array
    {
        if (! in_array($realm, [V2Realm::User, V2Realm::Admin], true)) {
            throw new InvalidArgumentException('Browser session is prohibited for this HTTP surface.');
        }

        /** @var array{table: string, cookie: string, idle_minutes: int, absolute_minutes: int, same_site: string, remember: bool} $policy */
        $policy = config("v2_identity.sessions.{$realm->value}");

        return $policy;
    }

    public function issueOpaqueSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashSessionId(#[SensitiveParameter] string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
