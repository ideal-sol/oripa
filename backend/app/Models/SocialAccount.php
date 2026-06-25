<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
        'linked_at',
        'last_login_at',
        'raw_profile',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'raw_profile' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
