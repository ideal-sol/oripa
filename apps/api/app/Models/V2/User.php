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
        'email_display',
        'email_normalized',
        'email_verified_at',
        'password_hash',
        'state',
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
            'state' => V2UserState::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->public_id ??= (string) Str::uuid();
        });
    }
}
