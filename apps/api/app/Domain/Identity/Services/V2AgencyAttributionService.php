<?php

namespace App\Domain\Identity\Services;

use App\Models\V2\Agency;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class V2AgencyAttributionService
{
    public function __construct(private readonly V2SessionPolicy $clock)
    {
    }

    public function candidate(mixed $code): ?string
    {
        return is_string($code) && preg_match('/\A[A-Za-z0-9]{8}\z/D', $code) === 1
            ? $code : null;
    }

    public function isValid(mixed $code): bool
    {
        $candidate = $this->candidate($code);

        return $candidate !== null && DB::table('agency_advertising_codes as code')
            ->join('agencies as agency', 'agency.id', '=', 'code.agency_id')
            ->where('code.code', $candidate)->where('agency.status', 'active')->exists();
    }

    public function attributeNewUserFromAdvertisingCode(User $user, mixed $code = null): void
    {
        $candidate = $this->candidate($code);
        if ($candidate === null || ! $user->wasRecentlyCreated) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Advertising attribution requires the User creation transaction.');
        }
        User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        if (DB::table('user_advertising_attributions')->where('user_id', $user->getKey())->exists()) {
            return;
        }
        $advertisingCode = DB::table('agency_advertising_codes')->where('code', $candidate)->first();
        if ($advertisingCode === null) {
            return;
        }
        $agency = Agency::query()->whereKey($advertisingCode->agency_id)->lockForUpdate()->firstOrFail();
        if ($agency->status !== 'active') {
            return;
        }
        DB::table('user_advertising_attributions')->insert([
            'user_id' => $user->getKey(),
            'advertising_code_id' => $advertisingCode->id,
            'attributed_at' => $this->clock->currentTime(),
        ]);
    }
}
