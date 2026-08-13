<?php

namespace App\Models\V2;

use App\Domain\Identity\Enums\V2UserState;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

final class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'public_id',
        'referral_code',
        'display_name',
        'email_display',
        'email_normalized',
        'email_verified_at',
        'password_hash',
        'password_login_enabled',
        'state',
        'state_revision',
    ];

    protected $hidden = [
        'email_display',
        'email_normalized',
        'password_hash',
    ];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password_login_enabled' => 'boolean',
            'state' => V2UserState::class,
            'state_revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->public_id ??= (string) Str::uuid();
            $user->referral_code ??= self::generateReferralCode();
        });
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = 'LP'.Str::random(10);
        } while (self::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
