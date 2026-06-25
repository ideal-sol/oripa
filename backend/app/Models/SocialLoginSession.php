<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialLoginSession extends Model
{
    protected $fillable = [
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
        'status',
        'token_hash',
        'expires_at',
        'completed_at',
        'raw_profile',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'raw_profile' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }
}
