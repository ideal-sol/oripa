<?php

namespace App\Domain\Identity\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2RegistrationQualification
{
    public function origin(object $user, string $legacyColumn): ?CarbonImmutable
    {
        $activated = DB::table('pre_registration_grants as grant')
            ->join('users', 'users.id', '=', 'grant.user_id')
            ->where('grant.user_id', $user->id)->value('users.created_at');
        $value = $activated ?? $user->{$legacyColumn};
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
