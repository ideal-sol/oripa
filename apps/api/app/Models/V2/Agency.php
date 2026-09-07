<?php

namespace App\Models\V2;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class Agency extends Authenticatable
{
    protected $table = 'agencies';
    protected $guarded = ['*'];
    protected $hidden = ['password_hash', 'memo', 'normalized_email'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }
}
