<?php

namespace Tests\V2\Fixtures;

use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Illuminate\Support\Facades\Crypt;

final class SmsVerifiedUser
{
    public static function create(array $attributes): User
    {
        $user = User::query()->create($attributes);
        UserPhoneNumber::query()->create([
            'user_id' => $user->id,
            'phone_ciphertext' => Crypt::encryptString('+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT)),
            'phone_hmac' => hash('sha256', 'sms-fixture:'.$user->public_id),
            'verified_at' => now(),
        ]);

        return $user;
    }
}
