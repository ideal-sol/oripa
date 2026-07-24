<?php

namespace App\Models\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

final class Admin extends Authenticatable
{
    protected $table = 'admins';

    protected $fillable = [
        'public_id',
        'email_display',
        'email_normalized',
        'email_verified_at',
        'password_hash',
        'role',
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
            'role' => V2AdminRole::class,
            'state' => V2AdminState::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $admin): void {
            $admin->public_id ??= (string) Str::uuid();
        });
    }
}
